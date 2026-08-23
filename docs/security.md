---
title: Security
weight: 30
---

## Introduction

The package's security model gives every service its own ECDSA (P-256) key pair. Outbound requests are signed automatically by the [client](client.md) with the sending service's own private key; inbound requests are verified against the public key it published. Compromising one service's private key only lets an attacker forge traffic *as that service* — it never exposes any other service's signing capability.

This page covers the verification side: how peer trust is resolved, the middleware you apply to incoming routes, and the idempotency layer that protects mutating endpoints from duplicate processing.

### How Peer Trust Works

A service's public key travels as part of its own manifest, the same endpoint already used for route discovery. Verifying an incoming request from `oms` means resolving `oms`'s manifest and reading its public key out of it — the same lookup as resolving `oms`'s `base_url`.

Two sources are checked, in order: a manifest already cached locally (from a sync, or a previous request from that peer), and failing that, a live fetch using the same `SERVICE_DISCOVERY_PATTERN` the client uses for discovery. The result is cached the same way any manifest is, so this only costs a network round trip the first time a given peer is seen, or after its cache entry expires.

If a signature fails to verify against a cached key, the resolver refetches once before giving up, in case the peer rotated its key since the last sync — so a rotation propagates as soon as the next call happens, not only on the cache's own TTL.

> [!IMPORTANT]
> Trust is bounded by network reachability: anything that can answer `GET /microservice/manifest` under a service's discovered address is trusted under that name. That's an appropriate boundary inside a private cluster network — the same one your `base_url`s and Redis already rely on — and not appropriate if these endpoints are reachable from outside it.

> [!WARNING]
> Setting `SERVICE_DEBUG=true` disables all signature verification. Local development only — never in production.

## Middleware

### Trust Peer

The `TrustPeer` middleware verifies the ECDSA signature on an incoming request against the public key published in the manifest of the service named in `X-Service-Name`. Apply it to any route that accepts calls from another service, whether the call arrives directly or proxied through a [gateway](gateway.md):

```php
use Jurager\Microservice\Http\Middleware\TrustPeer;

Route::middleware(TrustPeer::class)->group(function () {
    Route::get('/v1/products', [ProductController::class, 'index']);
});
```

Three headers carry everything the middleware needs, and it checks them in order — each has to be present before the next one is even worth checking:

```
X-Signature    the request's ECDSA signature
X-Timestamp    when the request was signed, for replay protection
X-Service-Name which service claims to have sent it
```

A request missing `X-Signature` or `X-Timestamp` is rejected with `401` and a `MissingSignatureException`. Missing `X-Service-Name` gets a `MissingServiceNameException` — verification has to know who claims to have signed the request before it can resolve a public key to check it with. Once both are present, a signature that doesn't match the body, path, or timestamp — or that names a service whose manifest can't be resolved at all — is rejected with `401` and an `InvalidSignatureException`.

The default timestamp tolerance is 60 seconds (`microservice.timestamp_tolerance`), which protects against replay attacks while still accommodating modest clock skew between hosts.

## Idempotency

The `Idempotency` middleware deduplicates mutating requests using the `X-Request-Id` header, so a request that's retried after a dropped connection — the caller never actually saw whether it succeeded — doesn't get processed twice:

```php
use Jurager\Microservice\Http\Middleware\Idempotency;

Route::middleware(Idempotency::class)->group(function () {
    Route::post('/v1/orders', OrderController::class);
});
```

You don't need to apply this middleware to gateway proxy routes yourself — they include it automatically, since a request retried at the client is retried through the gateway too.

### How Requests Are Matched

Only `POST`, `PUT`, `PATCH`, and `DELETE` requests are deduplicated; `GET` and `HEAD` pass through untouched, since they're expected to be safe to repeat already. When `X-Request-Id` is absent the middleware is a no-op — idempotency is opt-in per caller, not forced onto every mutating request. When it's present, it must be a valid UUID v4; anything else gets a `400` response rather than being silently accepted as an arbitrary string.

The same id sent twice within the cache TTL (`SERVICE_IDEMPOTENCY_TTL`, 86400 seconds by default) gets the cached response back on the second call instead of being processed again. Cached responses carry an `X-Idempotency-Cache-Hit: true` header, so a caller — or you, while debugging — can always tell which path actually served a given response.

### Concurrent Requests

Two requests carrying the same id that arrive at the same time are a slightly different problem: the first one hasn't finished yet, so there's no cached response to serve. The middleware takes a short-lived cache lock (`idempotency.lock_timeout`, 10 seconds by default) around the first request's processing; a second request that arrives while that lock is held gets `409 Conflict` immediately, rather than being allowed to run the same mutation concurrently.

### Streamed Responses

Gateway proxy responses are streamed to the client rather than buffered — that's what lets a large response start reaching the client before the whole thing has arrived from the downstream service. Caching a streamed response for replay means capturing its content as it flows past, so a proxied response covered by an `X-Request-Id` is delivered in one piece instead of chunk by chunk; a request without the header keeps streaming untouched, since there's nothing to cache.

### Body Size Limits

Bodies larger than `idempotency.max_body_size` (1 MB by default, `SERVICE_IDEMPOTENCY_MAX_BODY_SIZE`, `0` disables the limit) are still returned to the caller in full — the request itself isn't affected — but they're not written to the cache. A repeat of a request whose response was too large to cache is simply processed again, rather than deduplicated; large idempotent responses are assumed to be rare enough that reprocessing is cheaper than caching them.

## Exception Reference

The following exceptions are thrown by the security and transport layers. All of them render as JSON:API error documents when uncaught, thanks to the package's auto-registered exception renderer:

| Exception | Thrown When |
|---|---|
| `ServiceRequestException` | A service returned a non-2xx response |
| `ServiceUnavailableException` | A service URL cannot be resolved, the request fails at the transport level, or its circuit breaker is open |
| `MissingSignatureException` | `X-Signature` or `X-Timestamp` header is absent on an incoming request |
| `InvalidSignatureException` | Signature does not match, the timestamp is outside the tolerance window, or the claimed service's public key couldn't be resolved |
| `MissingServiceNameException` | `X-Service-Name` header is required but absent |
| `InvalidRequestIdException` | `X-Request-Id` is not a valid UUID v4 |
| `DuplicateRequestException` | A duplicate in-flight idempotent request was detected |
