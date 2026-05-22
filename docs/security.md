---
title: Security
weight: 30
---

## Introduction

The package's security model rests on a single shared secret distributed across every service in the cluster. Outbound requests are signed automatically by the [client](client.md); inbound requests are verified by middleware. This section covers the verification side — the middleware you apply to incoming routes and the idempotency layer that protects mutating endpoints from duplicate processing.

Everything here assumes `SERVICE_SECRET` is identical across the cluster. A mismatch causes every signed request to be rejected with `401`.

## Middleware

### Trust Gateway

The `TrustGateway` middleware verifies the HMAC signature on incoming requests. You should apply it to any route that accepts calls proxied from a gateway:

```php
use Jurager\Microservice\Http\Middleware\TrustGateway;

Route::middleware(TrustGateway::class)->group(function () {
    Route::get('/v1/products', [ProductController::class, 'index']);
});
```

Requests without an `X-Signature` header are rejected with `401` and a `MissingSignatureException`. Requests whose signature doesn't match the body, path, and timestamp are rejected with the same status and an `InvalidSignatureException`. The default timestamp tolerance is 60 seconds, which protects against replay attacks while accommodating modest clock skew between hosts.

**`SERVICE_DEBUG=true` disables all signature verification.** This is convenient for local development but unsafe anywhere else — never enable it in production.

### Trust Service

`TrustService` extends `TrustGateway` with an additional check: the request must carry an `X-Service-Name` header identifying the calling service. Use it on routes that accept direct service-to-service calls, bypassing the gateway:

```php
use Jurager\Microservice\Http\Middleware\TrustService;

Route::middleware(TrustService::class)->group(function () {
    Route::post('/v1/internal/sync', SyncController::class);
});
```

The middleware throws `MissingServiceNameException` (also `401`) when the header is absent.

## Idempotency

The `Idempotency` middleware deduplicates mutating requests using the `X-Request-Id` header. The same request id sent twice within the cache TTL receives the cached response instead of being processed twice:

```php
use Jurager\Microservice\Http\Middleware\Idempotency;

Route::middleware(Idempotency::class)->group(function () {
    Route::post('/v1/orders', OrderController::class);
});
```

The middleware behaves as follows:

- Only `POST`, `PUT`, `PATCH`, and `DELETE` requests are deduplicated. `GET` and `HEAD` requests pass through untouched.
- When the `X-Request-Id` header is absent, the middleware is a no-op.
- The `X-Request-Id` header must be a valid UUID v4; other values cause a `400` response.
- Two requests with the same id arriving simultaneously cause the second to receive `409 Conflict`.
- Only `2xx` responses are cached. Cached responses include the `X-Idempotency-Cache-Hit: true` header so callers can tell which path served them.

You don't need to apply this middleware to gateway proxy routes — they include it automatically.

## Exception Reference

The following exceptions are thrown by the security and transport layers. All of them render as JSON:API error documents when uncaught, thanks to the package's auto-registered exception renderer:

| Exception | Thrown When |
|---|---|
| `ServiceRequestException` | A service returned a non-2xx response |
| `ServiceUnavailableException` | A service URL cannot be resolved, or the request fails at the transport level |
| `MissingSignatureException` | `X-Signature` or `X-Timestamp` header is absent on an incoming request |
| `InvalidSignatureException` | Signature does not match or the timestamp is outside the tolerance window |
| `MissingServiceNameException` | `X-Service-Name` header is required but absent |
| `InvalidRequestIdException` | `X-Request-Id` is not a valid UUID v4 |
| `DuplicateRequestException` | A duplicate in-flight idempotent request was detected |
