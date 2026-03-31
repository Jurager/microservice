---
title: Client
weight: 20
---

# Client

Use `ServiceClient` to send HMAC-signed HTTP requests to other services.

```php
use Jurager\Microservice\Client\ServiceClient;

$response = app(ServiceClient::class)
    ->service('pim')
    ->get('/api/products/42')
    ->send()
    ->throw();

$product = $response->json('data');
```

## Request Builder

```php
$client = app(ServiceClient::class);

$client->service('oms')
    ->post('/api/orders', ['sku' => 'A1'])
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

> [!NOTE]
> `X-Request-Id` is not added automatically. Set it yourself if you need idempotency on the receiving end.

## URL Resolution

1. `SERVICE_DISCOVERY_PATTERN` — if set, substitutes `{service}` in the pattern.
2. Manifest in Redis — reads `base_url` from the manifest stored by `microservice:sync`.

If neither resolves, `ServiceUnavailableException` is thrown.

## Response

```php
$response->status();          // HTTP status code
$response->ok();              // true if 2xx
$response->failed();          // true if 4xx or 5xx
$response->json();            // decoded JSON body
$response->json('data.id');   // dot-notation access
$response->body();            // raw string body
$response->header('X-Total');
$response->headers();
$response->toPsrResponse();   // PSR-7 ResponseInterface
$response->throw();           // throws ServiceRequestException if failed()
```

## Error Handling

```php
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Exceptions\ServiceRequestException;

try {
    $response = $client->service('oms')->get('/api/orders')->send()->throw();
} catch (ServiceUnavailableException $e) {
    // service unreachable or URL unresolvable
} catch (ServiceRequestException $e) {
    // 4xx / 5xx from the service
}
```

> [!NOTE]
> Retries and failover are not handled by the package. Use Kubernetes liveness/readiness probes and load balancer retry policies.
