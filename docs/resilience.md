---
title: Retries and circuit breaker
weight: 23
---

Both retries and the circuit breaker are disabled by default. Enable them when your service needs to tolerate temporary failures.

## Retries

Enable retries with:

```env
SERVICE_RETRIES_MAX=3
SERVICE_RETRIES_DELAY=100
SERVICE_RETRIES_MULTIPLIER=2
```

These settings allow three retries, waiting 100, 200, and 400 milliseconds. Set `SERVICE_RETRIES_MAX=0` to disable retries.

Retry behavior:

- A read (`GET`, `HEAD`, `OPTIONS`) is retried on a failed connection and on a `5xx` (except `501` and `505`).
- A write is retried only when its connection never opened — the host could not be resolved or refused the connection — because a write that may have reached the peer could otherwise be applied twice.
- A write marked `->idempotent()` is retried like a read. Mark a write idempotent when the receiving service deduplicates it by its `X-Request-Id`, as the [idempotency middleware](security.md#idempotency) does.

A `4xx` means the request itself was rejected; it is never retried.

## Circuit breaker

The circuit breaker stops requests after repeated service failures and throws `ServiceUnavailableException` during cooldown.

It's disabled by default. Enable it by setting a failure threshold:

```env
SERVICE_CIRCUIT_BREAKER_THRESHOLD=5
SERVICE_CIRCUIT_BREAKER_TIMEOUT=30
SERVICE_CIRCUIT_BREAKER_WINDOW=60
```

The circuit opens after five consecutive failures within the 60-second window. Requests are blocked for 30 seconds. After cooldown, one probe is allowed: success closes the circuit; failure starts another cooldown. A threshold of `0` disables the breaker.

Bypass the circuit for a specific request:

```php
use Jurager\Microservice\Facades\Microservice;

Microservice::service('orders')->get('/v1/health')->withoutCircuitBreaker()->send();
```

You can inspect circuit state in the [health report](health.md).
