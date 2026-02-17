---
title: Client
weight: 40
---

# Client API

Use `ServiceClient` to send signed requests with retries and failover.

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
    ->retries(1)
    ->send();
```

> [!NOTE]
> Request bodies are JSON-encoded. Use arrays or JSON-serializable data.

Available methods:

- `withHeaders(array)`
- `withQuery(array)`
- `withBody(array)`
- `timeout(int)`
- `retries(int)`
- `send()`

Priority for `timeout` and `retries`:

1. Request overrides
2. Per-service config
3. Defaults

## ServiceResponse

```php
$response->status();
$response->ok();
$response->failed();
$response->json();
$response->json('data.id');
$response->body();
$response->header('X-Total');
$response->headers();
$response->toPsrResponse();
$response->throw();
```

## Retry and Failover

- 5xx and network errors: retry on the same instance, then move to the next instance.
- 4xx: returned immediately (no retry).
- If all healthy instances fail, the client retries the full list once more.
- If all attempts fail, `ServiceUnavailableException` is thrown by default.

To propagate the original exception instead, enable `propagate_exception` in config or via env:

```dotenv
SERVICE_PROPAGATE_EXCEPTION=true
```

When enabled:

- **5xx response** — `ServiceRequestException` is thrown with the original microservice response. You can access the full response body, status, and JSON:

  ```php
  try {
      $client->service('oms')->get('/api/orders')->send();
  } catch (\Jurager\Microservice\Exceptions\ServiceRequestException $e) {
      $message = $e->response->json('message');
      $status  = $e->response->status();
  }
  ```

- **Connection failure** — the underlying `ConnectException` is re-thrown, preserving the network error message.

> [!NOTE]
> Requests are always JSON and signed with `X-Service-Name`, `X-Timestamp`, and `X-Signature`.

> [!NOTE]
> The client does not generate `X-Request-Id`. Add it yourself if you need idempotency.
