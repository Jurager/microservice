---
title: Security
weight: 30
---

# Security

## Exceptions

| Exception | Thrown when |
|---|---|
| `ServiceRequestException` | Non-2xx response from a service |
| `ServiceUnavailableException` | Service URL cannot be resolved or request fails at transport level |
| `MissingSignatureException` | `X-Signature` or `X-Timestamp` header is absent |
| `InvalidSignatureException` | Signature mismatch or timestamp outside tolerance |
| `InvalidRequestIdException` | `X-Request-Id` is not a valid UUID v4 |
| `DuplicateRequestException` | Duplicate in-flight idempotent request |

## Middleware

### TrustGateway

Verifies the HMAC signature on incoming requests. Apply to routes that accept calls proxied from a gateway:

```php
use Jurager\Microservice\Http\Middleware\TrustGateway;

Route::middleware(TrustGateway::class)->group(function () {
    Route::get('/v1/products', [ProductController::class, 'index']);
});
```

Returns `401` if the signature is missing or invalid.

> [!WARNING]
> `SERVICE_DEBUG=true` disables all signature verification. Never enable it in production.

## Idempotency

The `Idempotency` middleware deduplicates mutating requests via `X-Request-Id`:

```php
use Jurager\Microservice\Http\Middleware\Idempotency;

Route::middleware(Idempotency::class)->group(function () {
    Route::post('/v1/orders', OrderController::class);
});
```

- Applies to POST, PUT, PATCH, DELETE only — GET/HEAD pass through untouched.
- Skipped when `X-Request-Id` is absent.
- `X-Request-Id` must be a valid UUID v4, otherwise returns `400`.
- Duplicate in-flight requests return `409`.
- Only `2xx` responses are cached. Cached responses include `X-Idempotency-Cache-Hit: true`.

> [!NOTE]
> Gateway proxy routes include `Idempotency` automatically.
