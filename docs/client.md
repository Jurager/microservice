---
title: Client
weight: 20
---

# Client

Use `ServiceClient` to send HMAC-signed HTTP requests to other services.

## Sending Requests

```php
use Jurager\Microservice\Client\ServiceClient;

$client = app(ServiceClient::class);

$response = $client->service('pim')
    ->get('/v1/products/42')
    ->with(['include' => 'categories'])
    ->timeout(5)
    ->send();
```

| Method | Description |
|---|---|
| `get(string $path)` | |
| `post(string $path, ?array $body)` | |
| `put(string $path, ?array $body)` | |
| `patch(string $path, ?array $body)` | |
| `delete(string $path)` | |
| `with(array $query)` | Append query parameters |
| `withHeaders(array $headers)` | Merge extra headers |
| `withBody(array $body)` | Set JSON body |
| `timeout(int $seconds)` | Per-request timeout override |
| `withoutErrors()` | Suppress upstream error details in the exception |
| `send(): ServiceResponse` | Execute — throws `ServiceRequestException` on non-2xx |

## ServiceResponse

```php
$response->status();        // HTTP status code
$response->ok();            // true if 2xx
$response->failed();        // true if 4xx or 5xx
$response->json();          // decoded array
$response->json('data.id'); // dot-notation access
$response->body();          // raw string
$response->header('X-Total');
```

## Parallel Requests

Send multiple requests concurrently. All are dispatched at the same time; the method blocks until all complete. Keys are preserved in the response array.

```php
$responses = $client->parallel([
    'catalog'   => $client->service('pim')->get('/v1/categories/5'),
    'warehouse' => $client->service('pim')->get('/v1/warehouses/12'),
    'prices'    => $client->service('oms')->get('/v1/price-types/3'),
]);

$responses['catalog']->ok();
$responses['warehouse']->json();
```

Transport failures throw `ServiceUnavailableException`. Non-2xx responses are returned as-is — inspect `->status()` yourself.

Typical validation pattern:

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

When the remote service returns JSON:API, use typed wrappers instead of raw `json()`:

```php
$response->collect(ProductItem::class)  // CollectionDocument<ProductItem>
$response->item(ProductItem::class)     // ItemDocument<ProductItem>
```

### CollectionDocument

```php
$document = $client->service('pim')
    ->get('/v1/products')
    ->with(['filter[catalog_id][in]' => '1,2'])
    ->collect(ProductItem::class);

$document->data();    // Collection<ProductItem>
$document->first();   // ProductItem|null
$document->meta();    // array
$document->total();   // ?int  (from meta.total)
```

Pass-through or transform before returning to the client:

```php
// Forward as-is
return $document->toResponse();

// Enrich each item
return $document->toResponse(function (ProductItem $item) {
    return array_merge($item->toArray(), [
        'attributes' => array_merge($item->attributes(), [
            'in_wishlist' => $this->wishlist->has($item->id),
        ]),
    ]);
});
```

Resolve included relationships:

```php
$document->withRelations(['categories' => CategoryItem::class]);

foreach ($document->data() as $product) {
    $product->getRelation('categories');  // Collection<CategoryItem>
}
```

### ItemDocument

```php
$document = $client->service('sfm')
    ->get("/v1/sites/{$id}")
    ->item(SiteItem::class);

$document->data();   // SiteItem
return $document->toResponse();
```

### Item

Subclass `Item` to add domain-specific accessors:

```php
use Jurager\Microservice\JsonApi\Item;

class ProductItem extends Item
{
    public function name(): string   { return $this->attribute('name', ''); }
    public function isActive(): bool { return (bool) $this->attribute('is_active'); }
}
```

| Method | Description |
|---|---|
| `$item->id` | Resource id |
| `$item->type` | Resource type |
| `$item->attribute(key, default)` | Single attribute with dot-notation |
| `$item->attributes()` | All attributes as array |
| `$item->relationIds(name)` | To-many linkage → `int[]` |
| `$item->relationId(name)` | To-one linkage → `?int` |
| `$item->getRelation(name)` | Resolved included collection |
| `$item->getRelationOne(name)` | Resolved included single item |
| `$item->toArray()` | Serialize back to JSON:API resource array |

## Error Handling

```php
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Exceptions\ServiceRequestException;

try {
    $response = $client->service('oms')->get('/v1/orders')->send();
} catch (ServiceUnavailableException $e) {
    // service unreachable
} catch (ServiceRequestException $e) {
    $e->status; // upstream HTTP status
    $e->errors; // upstream errors array, null when withoutErrors() was used
}
```

The package auto-registers a JSON:API exception renderer. All exceptions — `ValidationException`, `ModelNotFoundException`, standard HTTP exceptions — are rendered as `application/vnd.api+json` without any configuration.
