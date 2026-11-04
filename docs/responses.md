---
title: Reading service responses
weight: 21
---

`send()` returns a `ServiceResponse`, which supports Laravel's HTTP response methods:

```php
use Jurager\Microservice\Facades\Microservice;

$response = Microservice::service('catalog')->get('/v1/products/42')->send();

$response->status();
$response->successful();
$response->json('data.id');
$response->collect('data');
$response->body();
$response->header('Content-Type');
```

To forward the response from a controller, return `$response->passthrough()`. It preserves the status, body, and response headers, excluding connection headers.

## Reading JSON:API documents

Define an item class for the resources you receive:

```php
use Jurager\Microservice\JsonApi\Item;

class ProductItem extends Item
{
    public function name(): string
    {
        return $this->attribute('name', '');
    }
}
```

Use `collect()` to send a request and read a collection document:

```php
$document = Microservice::service('catalog')
    ->get('/v1/products')
    ->collect(ProductItem::class);

$document->data();  // Collection of ProductItem instances.
$document->first(); // First item, or null.
$document->meta();
$document->total(); // From meta.total, when present.
```

For one resource, use `item()`:

```php
$document = Microservice::service('catalog')
    ->get('/v1/products/42')
    ->item(ProductItem::class);

$product = $document->data();
$product->name();
```

For an existing response, use `$response->collection(ProductItem::class)` or `$response->item(ProductItem::class)`.

### Item accessors

| Accessor | Returns |
|---|---|
| `$item->id`, `$item->type` | Resource identity |
| `$item->attribute('name', '')` | An attribute, with a default |
| `$item->attributes()` | All attributes |
| `$item->relationIds('categories')` | To-many linkage IDs |
| `$item->relationId('brand')` | A to-one linkage ID |
| `$item->links()` | Resource links |
| `$item->toArray()` | The JSON:API resource array |

### Included resources

Map included relationships to their item classes:

```php
$document->withRelations(['categories' => CategoryItem::class]);

foreach ($document->data() as $product) {
    $categories = $product->getRelation('categories');
}
```

Use `getRelationOne()` for a resolved to-one relationship.

### Returning a document

You can return a document from a controller. Pagination and resource links are rewritten for the request it answers:

```php
return $document;
```

To change primary resources before returning them, use `through()`:

```php
return $document->through(fn (ProductItem $item) => [
    ...$item->toArray(),
    'attributes' => [...$item->attributes(), 'in_wishlist' => $this->wishlist->has($item->id)],
]);
```

### Skipping the request

`skipIf()` returns an empty document when the condition is true:

```php
$document = Microservice::service('orders')->get('/v1/orders')
    ->withQueryParameters(['customer_id' => $customerId])
    ->skipIf($customerId === null)
    ->collect(OrderItem::class);
```

This works with `collect()` and `item()`; calling `send()` on a skipped request throws `LogicException`. You can also create documents directly with `CollectionDocument::empty(ItemClass::class)` or `ItemDocument::empty(ItemClass::class)`.

### Transforming queries and responses

Use `mapQuery()` to change query parameters before sending. `mapBody()` changes the decoded response before creating a document:

```php
$document = Microservice::service('catalog')->get('/v1/products')
    ->mapQuery(fn (array $query) => [...$query, 'sort' => 'name'])
    ->mapBody(fn (array $body) => [...$body, 'meta' => ['source' => 'catalog']])
    ->collect(ProductItem::class);
```
