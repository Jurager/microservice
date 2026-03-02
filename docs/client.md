---
title: Client
weight: 40
---

# Client API

Use `ServiceClient` to send HMAC-signed HTTP requests to other services.

## Basic Usage

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
    ->withHeaders(['X-Request-Id' => $id])
    ->withQuery(['debug' => 1])
    ->timeout(3)
    ->send();
```

> [!NOTE]
> Request bodies are JSON-encoded. Use arrays only.

Available builder methods:

- `get(string $path)`
- `post(string $path, ?array $body = null)`
- `put(string $path, ?array $body = null)`
- `patch(string $path, ?array $body = null)`
- `delete(string $path)`
- `withHeaders(array $headers)`
- `withQuery(array $query)`
- `withBody(array $body)`
- `timeout(int $seconds)`
- `send(): ServiceResponse`

Timeout resolution order:

1. Per-request `->timeout(n)`
2. `timeout` from the service manifest (published by the target service)
3. `defaults.timeout` from config

## URL Resolution

`ServiceClient` resolves the target URL using:

1. `SERVICE_DISCOVERY_PATTERN` — if set, substitutes `{service}` in the pattern.
2. Service manifest in Redis — reads `base_url` from the manifest stored by `microservice:sync`.

If neither resolves a URL, `ServiceUnavailableException` is thrown with a clear message.

> [!NOTE]
> Retries and failover are not handled by the package. Use Kubernetes liveness/readiness probes and load balancer retry policies.

## ServiceResponse

```php
$response->status();         // HTTP status code
$response->ok();             // true if 2xx
$response->failed();         // true if 4xx or 5xx
$response->json();           // decoded JSON body
$response->json('data.id');  // dot-notation access
$response->body();           // raw string body
$response->header('X-Total');
$response->headers();
$response->toPsrResponse();  // PSR-7 ResponseInterface
$response->throw();          // throws RuntimeException if failed()
```

## Errors

If the target service is unreachable or URL cannot be resolved, `ServiceUnavailableException` is thrown.

```php
use Jurager\Microservice\Exceptions\ServiceUnavailableException;

try {
    $response = $client->service('oms')->get('/api/orders')->send();
} catch (ServiceUnavailableException $e) {
    // service unreachable
}
```

> [!NOTE]
> Requests are always JSON and signed with `X-Service-Name`, `X-Timestamp`, and `X-Signature`.

> [!NOTE]
> The client does not generate `X-Request-Id`. Add it yourself if you need idempotency on the receiving end.
