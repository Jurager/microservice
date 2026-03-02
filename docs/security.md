---
title: Security
weight: 50
---

# Security and Idempotency

## HMAC Signing

Every inter-service request includes:

- `X-Signature` — HMAC-SHA256 of the payload
- `X-Timestamp` — Unix timestamp of the request
- `X-Service-Name` — name of the calling service

Payload format:

```text
{METHOD}\n{PATH}\n{TIMESTAMP}\n{BODY}
```

> [!NOTE]
> The path is normalized with a leading `/`. The body is raw JSON or an empty string.

> [!NOTE]
> Ensure service clocks are in sync. Requests with a timestamp outside `timestamp_tolerance` (default 60 seconds) are rejected.

> [!NOTE]
> Query parameters are not part of the signature. Only method, path, timestamp, and body are signed.

## TrustGateway Middleware

Use `TrustGateway` on routes that accept proxied calls from a gateway.

```php
use Jurager\Microservice\Http\Middleware\TrustGateway;

Route::middleware(TrustGateway::class)->group(function () {
    Route::get('/api/products', [ProductController::class, 'index']);
});
```

Rejects with 401 if `X-Signature` or `X-Timestamp` is missing or invalid.

> [!NOTE]
> `TrustGateway` verifies signature and timestamp only. Use `TrustService` if you also require `X-Service-Name`.

> [!WARNING]
> `SERVICE_DEBUG=true` disables all signature verification. Use it only in local development.

## TrustService Middleware

`TrustService` extends `TrustGateway` and additionally requires `X-Service-Name` to be present.

```php
use Jurager\Microservice\Http\Middleware\TrustService;

Route::middleware(TrustService::class)->group(function () {
    Route::get('/microservice/manifest', [ManifestController::class, 'show']);
});
```

Used internally to protect the `GET /microservice/manifest` endpoint — only signed requests from known services are accepted.

## Idempotency

Idempotency is applied only when:

- The request method is not safe (POST, PUT, PATCH, DELETE)
- The `X-Request-Id` header is present (UUID v4 format)

Rules:

- Invalid UUID returns 400.
- Duplicate in-flight requests return 409.
- Only successful (2xx) responses are cached.
- Cached responses include `X-Idempotency-Cache-Hit: true`.

```php
use Jurager\Microservice\Http\Middleware\Idempotency;

Route::middleware(Idempotency::class)->group(function () {
    Route::post('/api/orders', OrderController::class);
});
```

> [!NOTE]
> If `X-Request-Id` is missing, the middleware passes through without any caching.

> [!NOTE]
> Idempotency state is stored in Redis using `microservice.redis.connection`.

> [!WARNING]
> Set `idempotency.lock_timeout` longer than your slowest request. If the lock expires before the request completes, a duplicate request may be processed concurrently.
