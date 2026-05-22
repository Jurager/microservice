---
title: Client
weight: 20
---

## Introduction

The `ServiceClient` is the package's HTTP client for talking to other services. Every request it sends is automatically signed with HMAC-SHA256, propagates trace headers, and resolves the destination service URL from either a configured discovery pattern or the gateway's manifest registry.

You may use the client to call any service in the cluster without knowing where it physically lives — the discovery layer handles that for you.

## Sending Requests

You may resolve the client from the container and start building a request with the `service` method:

```php
use Jurager\Microservice\Client\ServiceClient;

$client = app(ServiceClient::class);

$response = $client->service('pim')
    ->get('/v1/products/42')
    ->with(['include' => 'categories'])
    ->timeout(5)
    ->send();
```

The `service` method names the destination service. The HTTP verb (`get`, `post`, `put`, etc.) starts the request. Builder methods such as `with` and `withHeaders` may be chained in any order. The request is dispatched when you call `send`.

### Available Methods

| Method | Description |
|---|---|
| `get(string $path)` | Issue a GET request |
| `post(string $path, ?array $body)` | Issue a POST request with an optional JSON body |
| `put(string $path, ?array $body)` | Issue a PUT request |
| `patch(string $path, ?array $body)` | Issue a PATCH request |
| `delete(string $path)` | Issue a DELETE request |
| `with(array $query)` | Append query string parameters |
| `withHeaders(array $headers)` | Merge additional headers |
| `withBody(array $body)` | Set the JSON body |
| `timeout(int $seconds)` | Override the per-request timeout |
| `withoutErrors()` | Suppress upstream error details in raised exceptions |
| `send()` | Execute the request — throws `ServiceRequestException` on non-2xx |

## Working with Responses

The `send` method returns a `ServiceResponse` instance that wraps the raw HTTP response with convenient accessors:

```php
$response->status();        // HTTP status code (int)
$response->ok();            // true if 2xx
$response->failed();        // true if 4xx or 5xx
$response->json();          // decoded JSON as array
$response->json('data.id'); // dot-notation access into the decoded body
$response->body();          // raw response body
$response->header('X-Total');
```

For JSON:API responses you typically won't reach for `json()` directly — see [JSON:API Responses](#jsonapi-responses) for typed wrappers.

## Parallel Requests

To dispatch multiple requests concurrently, you may use the `parallel` method. All requests are sent at the same time, and the method blocks until every response arrives. Array keys are preserved so you may match responses back to their requests:

```php
$responses = $client->parallel([
    'catalog'   => $client->service('pim')->get('/v1/categories/5'),
    'warehouse' => $client->service('pim')->get('/v1/warehouses/12'),
    'prices'    => $client->service('oms')->get('/v1/price-types/3'),
]);

$responses['catalog']->ok();
$responses['warehouse']->json();
```

Transport-level failures throw `ServiceUnavailableException`. Non-2xx responses are returned as-is so you may inspect their status codes yourself — `parallel` does not throw on application errors.

A common pattern is batch-validating a list of identifiers in a single round trip:

```php
$requests = array_combine(
    $ids,
    array_map(fn (int $id) => $client->service('pim')->get("/v1/warehouses/$id"), $ids)
);

foreach ($client->parallel($requests) as $id => $response) {
    if ($response->status() === 404) {
        throw ValidationException::withMessages([
            'warehouse_id' => ["Warehouse [$id] not found."],
        ]);
    }
}
```

## JSON:API Responses

When the remote service returns a JSON:API document, you may use typed wrappers instead of working with the raw array. Two helpers on `ServiceResponse` produce them:

```php
$response->collect(ProductItem::class);  // CollectionDocument<ProductItem>
$response->item(ProductItem::class);     // ItemDocument<ProductItem>
```

Both wrappers handle relationship resolution, pagination metadata, and pass-through serialization for forwarding upstream documents to your own clients.

### Collection Documents

A `CollectionDocument` represents a JSON:API resource collection. You may access its data, metadata, and pagination details via dedicated methods:

```php
$document = $client->service('pim')
    ->get('/v1/products')
    ->with(['filter[catalog_id][in]' => '1,2'])
    ->collect(ProductItem::class);

$document->data();    // Collection<ProductItem>
$document->first();   // ProductItem|null
$document->meta();    // array
$document->total();   // ?int — from meta.total
```

To forward the document to your own client unchanged, call `toResponse`:

```php
return $document->toResponse();
```

To enrich each item before forwarding, pass a closure that returns a JSON:API resource array:

```php
return $document->toResponse(function (ProductItem $item) {
    return array_merge($item->toArray(), [
        'attributes' => array_merge($item->attributes(), [
            'in_wishlist' => $this->wishlist->has($item->id),
        ]),
    ]);
});
```

If the response includes related resources, you may resolve them into typed collections with `withRelations`:

```php
$document->withRelations(['categories' => CategoryItem::class]);

foreach ($document->data() as $product) {
    $product->getRelation('categories');  // Collection<CategoryItem>
}
```

### Item Documents

For single-resource responses, use `ItemDocument`:

```php
$document = $client->service('sfm')
    ->get("/v1/sites/{$id}")
    ->item(SiteItem::class);

$document->data();   // SiteItem
return $document->toResponse();
```

### Defining Custom Items

The `Item` base class exposes generic accessors. To add domain-specific helpers, you may subclass it:

```php
use Jurager\Microservice\JsonApi\Item;

class ProductItem extends Item
{
    public function name(): string
    {
        return $this->attribute('name', '');
    }

    public function isActive(): bool
    {
        return (bool) $this->attribute('is_active');
    }
}
```

The base class provides the following accessors out of the box:

| Method | Description |
|---|---|
| `$item->id` | Resource id |
| `$item->type` | Resource type |
| `$item->attribute(key, default)` | Single attribute with dot-notation access |
| `$item->attributes()` | All attributes as an array |
| `$item->relationIds(name)` | To-many linkage as `int[]` |
| `$item->relationId(name)` | To-one linkage as `?int` |
| `$item->getRelation(name)` | Resolved included collection |
| `$item->getRelationOne(name)` | Resolved single included item |
| `$item->toArray()` | Serialize back to a JSON:API resource array |

## Error Handling

The client raises two exception types depending on the nature of the failure:

```php
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Exceptions\ServiceRequestException;

try {
    $response = $client->service('oms')->get('/v1/orders')->send();
} catch (ServiceUnavailableException $e) {
    // Service is unreachable — DNS failure, connection refused, timeout, etc.
} catch (ServiceRequestException $e) {
    $e->status; // Upstream HTTP status code
    $e->errors; // Upstream errors array; null when withoutErrors() was used
}
```

The package auto-registers a JSON:API exception renderer. `ValidationException`, `ModelNotFoundException`, and the standard HTTP exceptions are all rendered as `application/vnd.api+json` without any configuration on your part.
