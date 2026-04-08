---
title: Client
weight: 20
---

# Client

Use `ServiceClient` to send HMAC-signed HTTP requests to other services.

```php
use Jurager\Microservice\Client\ServiceClient;

$client = app(ServiceClient::class);
```

## Request Builder

```php
$client->service('pim')
    ->get('/v1/products/42')
    ->withHeaders(['X-Request-Id' => $uuid])
    ->withQuery(['locale' => 'en'])
    ->timeout(5)
    ->send();
```

| Method | Description |
|---|---|
| `get(string $path)` | |
| `post(string $path, ?array $body = null)` | |
| `put(string $path, ?array $body = null)` | |
| `patch(string $path, ?array $body = null)` | |
| `delete(string $path)` | |
| `withHeaders(array $headers)` | Merge extra headers |
| `withQuery(array $query)` | Append query string |
| `withBody(array $body)` | Set JSON body |
| `timeout(int $seconds)` | Override per-request timeout |
| `send(): ServiceResponse` | Execute the request |

> [!NOTE]
> Request bodies are JSON-encoded. Pass arrays only.

## URL Resolution

1. `SERVICE_DISCOVERY_PATTERN` — if set, substitutes `{service}` in the pattern.
2. Manifest in Redis — reads `base_url` from the manifest stored by `microservice:sync`.

If neither resolves, `ServiceUnavailableException` is thrown.

## ServiceResponse

```php
$response = $client->service('pim')->get('/v1/products')->send();

$response->status();          // HTTP status code
$response->ok();              // true if 2xx
$response->failed();          // true if 4xx or 5xx
$response->json();            // decoded array
$response->json('data.id');   // dot-notation access
$response->body();            // raw string body
$response->header('X-Total');
$response->headers();
$response->toPsrResponse();   // PSR-7 ResponseInterface
$response->throw();           // throws ServiceRequestException if failed()
```

### JSON:API responses

When the remote service returns JSON:API, use one of:

```php
$response->collect(ProductItem::class)  // CollectionDocument<ProductItem>
$response->item(ProductItem::class)     // ItemDocument<ProductItem>
$response->passthrough()                // forward raw body to the client unchanged
```

## CollectionDocument

```php
$document = $client->service('pim')
    ->get('/v1/products')
    ->withQuery(['filter[catalog_id][in]' => '1,2'])
    ->send()
    ->collect(ProductItem::class);

$document->data();     // Collection<ProductItem>
$document->first();    // ProductItem|null
$document->meta();     // array
$document->links();    // array
$document->total();    // ?int  (from meta.total)
```

### Returning as response

```php
// Proxy — forward PIM response as-is
return $document->toResponse();

// Enrichment — transform each item
return $document->toResponse(function (ProductItem $item) {
    return array_merge($item->toArray(), [
        'attributes' => array_merge($item->attributes(), [
            'in_wishlist' => $this->wishlist->has($item->id),
        ]),
    ]);
});
```

### Resolving included relationships

```php
$document->withRelations([
    'categories'   => CategoryItem::class,
    'translations' => Item::class,
]);

foreach ($document->data() as $product) {
    $product->getRelation('categories');   // Collection<CategoryItem>
    $product->getRelationOne('brand');     // Item|null
}
```

## ItemDocument

```php
$document = $client->service('sfm')
    ->get("/v1/sites/{$id}")
    ->withQuery(['include' => 'catalogs,prices,warehouses'])
    ->send()
    ->item(SiteItem::class);

$document->data();    // SiteItem
$document->meta();    // array
$document->links();   // array
```

### Resolving included relationships

```php
$document->withRelations(['catalogs' => CatalogItem::class]);

$document->data()->getRelation('catalogs');  // Collection<CatalogItem>
```

### Returning as response

```php
return $document->toResponse();

return $document->toResponse(fn (SiteItem $item) => [
    'id'         => $item->id,
    'type'       => $item->type,
    'attributes' => $item->attributes(),
]);
```

## Item

`Item` is the base class for typed resource objects. Subclass it to add domain-specific accessors.

```php
use Jurager\Microservice\JsonApi\Item;

class ProductItem extends Item
{
    public function name(): string   { return $this->attribute('name', ''); }
    public function isActive(): bool { return (bool) $this->attribute('is_active'); }
    public function categoryIds(): array { return $this->relationIds('categories'); }
}
```

| Method | Description |
|---|---|
| `$item->id` | Resource id (string) |
| `$item->type` | Resource type (string) |
| `$item->name` | Magic access to attributes |
| `$item->attribute(key, default)` | Single attribute with dot-notation support |
| `$item->attributes()` | All attributes as array |
| `$item->relationIds(name)` | To-many linkage → `int[]` |
| `$item->relationId(name)` | To-one linkage → `?int` |
| `$item->getRelation(name)` | Resolved included collection (after `withRelations()`) |
| `$item->getRelationOne(name)` | Resolved included single item (after `withRelations()`) |
| `$item->toArray()` | Serialize back to JSON:API resource array |

## Error Handling

```php
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Exceptions\ServiceRequestException;

try {
    $response = $client->service('oms')->get('/v1/orders')->send()->throw();
} catch (ServiceUnavailableException $e) {
    // service unreachable or URL unresolvable
} catch (ServiceRequestException $e) {
    // 4xx / 5xx from the service
}
```

> [!NOTE]
> Retries and failover are not handled by the package. Use Kubernetes liveness/readiness probes and load balancer retry policies.
