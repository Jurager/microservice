---
title: Fetching in batches
weight: 22
---

You can send independent requests concurrently or split a large lookup into chunks.

```php
use Illuminate\Validation\ValidationException;
use Jurager\Microservice\Facades\Microservice;
```

## Parallel requests

Pass unsent requests to `parallel()`:

```php
$responses = Microservice::parallel([
    'catalog'   => Microservice::service('catalog')->get('/v1/categories/5'),
    'warehouse' => Microservice::service('catalog')->get('/v1/warehouses/12'),
    'prices'    => Microservice::service('orders')->get('/v1/price-types/3'),
]);

$responses['catalog']->successful();
$responses['warehouse']->json();
```

Results retain their array keys. Limit concurrency with `Microservice::parallel($requests, concurrency: 10)`.

Transport failures throw `ServiceUnavailableException`. HTTP errors are returned for inspection.

## Fetching in bulk

Use `chunk()` to fetch batches and `missing()` to check whether values exist.

### Chunking

Fetch many values in concurrent chunks:

```php
$products = Microservice::service('catalog')->post('/v1/products/search')
    ->chunk($productIds, fn ($request, $ids) => $request->withJson([
        'filter' => ['id' => ['in' => $ids]],
        'page' => ['size' => count($ids)],
    ]));
```

The callback configures a request for each chunk. The defaults are 100 values per chunk and five concurrent requests; pass `chunkSize` and `concurrency` to change them. Results combine response `data` arrays. Failed chunks throw `ServiceRequestException`.

### Checking existence

Use `missing()` to return values whose lookup responds with `404`:

```php
foreach (Microservice::service('catalog')->missing($priceTypeIds, fn ($id) => "/v1/price_types/{$id}") as $id) {
    throw ValidationException::withMessages([
        'price_type_id' => ["Price type [$id] not found."],
    ]);
}
```

Other errors throw `ServiceRequestException`; transport failures throw `ServiceUnavailableException`.
