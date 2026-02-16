---
title: Security
weight: 50
---

# Security and Idempotency

## HMAC Signing

Every service request includes:

- `X-Signature`
- `X-Timestamp`
- `X-Service-Name`

Payload format:

```text
{METHOD}\n{PATH}\n{TIMESTAMP}\n{BODY}
```

> [!NOTE]
> The path is normalized with a leading `/`. The body is raw JSON.

> [!NOTE]
> Ensure service clocks are in sync. Requests outside `timestamp_tolerance` are rejected.

> [!NOTE]
> Query parameters are not part of the signature. Only method, path, timestamp, and body are signed.

## TrustGateway Middleware

Use `TrustGateway` for routes that accept calls from a gateway.

```php
use Jurager\Microservice\Http\Middleware\TrustGateway;

Route::middleware(TrustGateway::class)->group(function () {
    Route::get('/api/products', [ProductController::class, 'index']);
});
```

If signature headers are missing or invalid, the request is rejected with 401.

> [!NOTE]
> `TrustGateway` checks only signature and timestamp. Use `TrustService` if you also require `X-Service-Name`.

> [!WARNING]
> `SERVICE_DEBUG=true` disables signature verification. Use it only locally.

## TrustService Middleware

`TrustService` extends `TrustGateway` and also requires `X-Service-Name`.

```php
use Jurager\Microservice\Http\Middleware\TrustService;

Route::middleware(TrustService::class)->group(function () {
    Route::post('/internal/sync', [SyncController::class, 'handle']);
});
```

## Idempotency

Idempotency is applied only when:

- The request method is not safe (POST, PUT, PATCH, DELETE)
- `X-Request-Id` header is present (UUID v4)

Rules:

- Invalid UUID returns 400.
- Duplicate in-flight requests return 409.
- Only successful (2xx) responses are cached.
- Cached responses include `X-Idempotency-Cache-Hit: true`.

> [!NOTE]
> If `X-Request-Id` is missing, the middleware passes through without caching.

> [!NOTE]
> Idempotency state is stored in Redis using `microservice.redis.connection`.

> [!WARNING]
> Set `idempotency.lock_timeout` longer than your slowest request. If the lock expires early, duplicates may execute.
