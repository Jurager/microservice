---
title: Verifying service requests
weight: 30
---

Each service signs requests and events with its own ECDSA key. Peers verify them using a certificate issued by the cluster CA.

### How peer trust works

Every service needs its own key and certificate, and the same `SERVICE_CA_PUBLIC_KEY`. Verification checks the certificate, service name, and request signature locally.

> [!IMPORTANT]
> A valid signature identifies the sender. Your application still controls what that service is allowed to do.

> [!WARNING]
> Only disable signing for local development. When `SERVICE_SIGNING_ENABLED=false`, requests and events are accepted without verification.

## Middleware

### Trust peer

Apply `TrustPeer` to routes that receive service requests:

```php
use Jurager\Microservice\Http\Middleware\TrustPeer;

Route::middleware(TrustPeer::class)->group(function () {
    Route::get('/v1/products', [ProductController::class, 'index']);
});
```

The client sends these headers automatically:

```
X-Signature    the request's ECDSA signature
X-Timestamp    when the request was signed, for replay protection
X-Service-Name which service claims to have sent it
X-Service-Cert the sender's certificate, proving its public key
```

Missing credentials, expired timestamps, and invalid signatures return `401`.

The signature covers the method, path, query, timestamp, request ID, and body. Multipart bodies are excluded.

Use `TrustPeer::peer($request)` to read the verified service name.

Set `microservice.timestamp_tolerance` to allow clock skew; the default is 60 seconds.

## Idempotency

Use `Idempotency` on write endpoints to replay responses for repeated `X-Request-Id` values:

```php
use Jurager\Microservice\Http\Middleware\Idempotency;

Route::middleware(Idempotency::class)->group(function () {
    Route::post('/v1/orders', OrderController::class);
});
```

Apply it to the service that handles the write. Gateways forward the request ID.

### How requests are matched

The middleware handles `POST`, `PUT`, `PATCH`, and `DELETE`. Requests without an ID pass through. An ID must be a UUID v4; invalid values return `400`.

A repeated ID with the same method and path returns the cached response with `X-Idempotency-Cache-Hit: true`. Responses are retained for `SERVICE_IDEMPOTENCY_TTL` seconds (default `86400`).

### Concurrent requests

Concurrent duplicates return the completed response or `409 Conflict`. Set `idempotency.lock_timeout` in seconds to cover the expected processing time.

### Streamed responses

A `StreamedResponse` is delivered as it is and never cached: reading its body would mean sending it.

### Body size limits

Responses over `SERVICE_IDEMPOTENCY_MAX_BODY_SIZE` (default 1 MB) are not cached, so repeat requests run again. Set `0` to remove the size limit.

## Exception reference

Invalid credentials and signatures return `401`; invalid request IDs return `400`; in-flight duplicates return `409`.

See [HTTP error handling](client.md#error-handling) for service request and transport exceptions.
