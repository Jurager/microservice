---
title: Security
weight: 30
---

# Security

## HMAC Signing

Every inter-service request carries three headers:

| Header | Value |
|---|---|
| `X-Signature` | HMAC-SHA256 of the payload |
| `X-Timestamp` | Unix timestamp of the request |
| `X-Service-Name` | Name of the calling service |

Signed payload format:

```
{METHOD}\n{PATH}\n{TIMESTAMP}\n{BODY}
```

Path is normalized with a leading `/`. Body is raw JSON or an empty string. Query parameters are not signed.

> [!NOTE]
> Ensure clocks are synchronized. Requests outside `timestamp_tolerance` (default 60 s) are rejected.

## Middleware

### TrustGateway

Verifies `X-Signature` and `X-Timestamp`. Use on routes that accept proxied calls from a gateway:

```php
use Jurager\Microservice\Http\Middleware\TrustGateway;

Route::middleware(TrustGateway::class)->group(function () {
    Route::get('/api/products', [ProductController::class, 'index']);
});
```

Returns `401` if the signature or timestamp is missing or invalid.

### TrustService

Extends `TrustGateway` and additionally requires `X-Service-Name`. Used internally to protect the `GET /microservice/manifest` endpoint:

```php
use Jurager\Microservice\Http\Middleware\TrustService;

Route::middleware(TrustService::class)->group(function () {
    Route::get('/microservice/manifest', [ManifestController::class, 'show']);
});
```

> [!WARNING]
> `SERVICE_DEBUG=true` disables all signature verification. Never enable it in production.

## Idempotency

The `Idempotency` middleware deduplicates mutating requests using `X-Request-Id`:

```php
use Jurager\Microservice\Http\Middleware\Idempotency;

Route::middleware(Idempotency::class)->group(function () {
    Route::post('/api/orders', OrderController::class);
});
```

Rules:

- Applied to POST, PUT, PATCH, DELETE only.
- Skipped silently when `X-Request-Id` is absent.
- `X-Request-Id` must be a valid UUID v4 — invalid values return `400`.
- Duplicate in-flight requests return `409`.
- Only `2xx` responses are cached. Cached responses include `X-Idempotency-Cache-Hit: true`.
- State is stored in Redis using `microservice.redis.connection`.

> [!NOTE]
> Gateway proxy routes automatically include the `Idempotency` middleware.

> [!WARNING]
> Set `idempotency.lock_timeout` longer than your slowest request. If the lock expires before the request completes, a duplicate may be processed concurrently.
