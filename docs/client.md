---
title: Sending service requests
weight: 20
---

You can send a signed request using the `Microservice` facade:

```php
use Jurager\Microservice\Facades\Microservice;

$response = Microservice::service('catalog')
    ->get('/v1/products/42')
    ->withQueryParameters(['include' => 'categories'])
    ->timeout(5)
    ->send();
```

The client resolves the service URL and adds signing headers automatically. A request is sent when you call `send()`, `collect()`, or `item()`.

## Sending requests

Use `get()`, `post()`, `put()`, `patch()`, or `delete()` to choose the method and path. `post()`, `put()`, and `patch()` accept a JSON body:

```php
$response = Microservice::service('orders')
    ->post('/v1/orders', ['product_id' => 42, 'quantity' => 2])
    ->send();
```

Writes receive an `X-Request-Id`, retained across retries for [idempotency](security.md#idempotency).

### Query parameters and headers

```php
$response = Microservice::service('catalog')
    ->get('/v1/products')
    ->withQueryParameters(['sort' => 'name'])
    ->mergeQueryParameters(['sort' => 'price'])
    ->withHeaders(['Accept-Language' => 'en'])
    ->send();
```

`withQueryParameters()` overwrites duplicate keys. `mergeQueryParameters()` combines arrays and comma-separated values, producing `sort=name,price` here. Both omit null values.

### Request bodies

You can set the JSON body with `withJson()` or merge fields with `mergeJson()`. Merging omits null fields:

```php
$response = Microservice::service('orders')->post('/v1/customers')
    ->mergeJson(['name' => $name, 'external_id' => $externalId])
    ->send();
```

To send raw content, use `withBody($content, $contentType)`.

### Uploading files

```php
$response = Microservice::service('catalog')->post('/v1/products/42/images')
    ->withMultipart([
        ['name' => 'alt_text', 'contents' => 'Front view'],
        ['name' => 'image', 'contents' => fopen($path, 'r'), 'filename' => 'front.jpg'],
    ])
    ->send();
```

Multipart replaces the JSON body. Its body is excluded from the request signature.

## Working with responses

`send()` returns a `ServiceResponse` with Laravel HTTP response methods:

```php
$response->status();
$response->json('data.id');
$response->successful();
```

See [reading responses](responses.md) for forwarding responses and using typed documents.

## JSON:API responses

```php
$products = Microservice::service('catalog')->get('/v1/products')->collect(ProductItem::class);
```

See [reading JSON:API documents](responses.md#reading-jsonapi-documents) to define `ProductItem` and work with included resources.

## Parallel requests

Use `Microservice::parallel()` to send requests concurrently. See [fetching in batches](batching.md).

## Fetching in bulk

Use `chunk()` for batches of values or `missing()` to check existence. See [bulk lookups](batching.md#fetching-in-bulk).

## Retries

[Enable retries](resilience.md#retries) for temporary connection and server failures. Mark a write `idempotent()` only when the receiving service deduplicates it.

## Circuit breaker

[Enable a circuit breaker](resilience.md#circuit-breaker) to stop calls to a failing service. Use `withoutCircuitBreaker()` to bypass it for one request.

## Distributed tracing

The client propagates W3C trace headers. Set `SERVICE_TRACING_ENABLED=false` to disable this. Register [LogContext](installation.md#middleware) to include request and trace IDs in logs, queued jobs, and events.

## Error handling

Non-2xx responses throw `ServiceRequestException`. Transport failures and open circuits throw `ServiceUnavailableException`.

Use `withoutThrowing()` to inspect an HTTP error response yourself:

```php
$response = Microservice::service('catalog')
    ->get('/v1/products/42')
    ->withoutThrowing()
    ->send();

if ($response->status() === 404) {
    // Handle the missing product.
}
```

`ServiceRequestException` exposes `status`, `errors`, and `service`. Use `withoutErrors()` to omit upstream errors from the exception.

When rendered to your client, upstream `4xx` responses retain their status and errors, except `401` and `407`. Those statuses and `5xx` responses become `502` with upstream details hidden. Transport failures also render as `502`.

## Testing

Use [service fakes](testing.md) to test requests without making network calls.
