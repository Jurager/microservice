---
title: Returning JSON:API resources
weight: 25
---

Use the package to serve compound JSON:API documents, sparse fieldsets, pagination, and errors. To read documents from other services, see [reading responses](responses.md).

## Compound documents

Add `WithEagerIncludes` to batch-load requested relations and avoid duplicate included resources:

```php
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Jurager\Microservice\JsonApi\Concerns\WithEagerIncludes;

class ProductResource extends JsonApiResource
{
    use WithEagerIncludes;
}
```

```php
return ProductResource::collection(Product::query()->paginate());
```

An include that names no relation of the model is rejected with `UnknownIncludeException` (`400`).

## Model contracts

Models may implement these optional contracts from `Jurager\Microservice\JsonApi\Contracts`.

### ProvidesEagerLoads

Define additional eager loads. The method receives requested includes and sparse fields, even when no includes are requested:

```php
// On a model implementing ProvidesEagerLoads:
public function eagerLoads(array $included, ?array $fields = null): array
{
    return $fields === null
        ? ['prices']
        : ['prices' => fn ($query) => $query->whereIn('type', $fields)];
}
```

Closure constraints replace already loaded relations. Plain relation names load only when missing.

### DefinesEagerRelations

Nested relations always loaded together with a relation the client includes:

```php
// On a model implementing DefinesEagerRelations:
public static function eagerRelations(): array
{
    return ['values' => ['values.attribute', 'values.translations']];
}
```

With this, `include=values` loads `values.attribute` and `values.translations` as well.

### MapsFieldsToRelations

Map sparse fields to the relations that provide them. When included, these fields are omitted from resource attributes:

```php
// On a model implementing MapsFieldsToRelations:
public static function fieldRelations(array $fields): array
{
    return ['attribute_values' => array_values(array_intersect($fields, ['color', 'size']))];
}
```

Models built on [jurager/eav](https://github.com/Jurager/eav) get `eagerLoads()` and `fieldRelations()` from its `InteractsWithSparseFieldsets` trait.

### FiltersIncludedRelations

Included relations constrained by `filter[included.{relation}.{column}]` — for example `?include=values&filter[included.values.tag_id]=1` loads only the matching values. [jurager/filterable](https://github.com/Jurager/filterable)'s `HasFilterable` trait implements it; the model declares the contract:

```php
use Jurager\Filterable\Concerns\HasFilterable;
use Jurager\Microservice\JsonApi\Contracts\FiltersIncludedRelations;

class Product extends Model implements FiltersIncludedRelations
{
    use HasFilterable;
}
```

## Pagination

Use `JsonApiPagination` for `page[number]` parameters, matching pagination links, and `meta.total`:

```php
use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Http\Middleware\JsonApiPagination;

Route::middleware(JsonApiPagination::class)->get('/v1/products', [ProductController::class, 'index']);
```

Gateway links use the original client URL only when `TrustPeer` has verified the request.

## Error documents

Exceptions of API requests — the `api` middleware group, or any request expecting JSON — are rendered as JSON:API error documents. Validation errors carry an RFC 6901 pointer per field; the message of an unexpected exception is replaced with a generic text outside `APP_DEBUG`.

To keep your application's own rendering, turn this off:

```env
SERVICE_RENDER_EXCEPTIONS=false
```

To render the error document differently, register a renderer of your own:

```php
use Jurager\Microservice\JsonApi\ResponseError;

ResponseError::renderUsing(
    fn (array $errors, int $status, array $headers) =>
        response()->json(['problems' => $errors], $status, $headers)
);
```
