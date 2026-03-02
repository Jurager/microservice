---
title: Operations
weight: 70
---

# Operations

## Artisan Commands

| Command | Where | Description |
| --- | --- | --- |
| `microservice:register` | Microservice | Build and store the local manifest in Redis (for local reference) |
| `microservice:sync` | Gateway | Pull manifests from all configured services and store in gateway Redis |
| `microservice:sync oms pim` | Gateway | Pull manifests from specific services only |

### microservice:register

Builds the manifest from current routes and stores it in the service's local Redis. Useful during development or for verifying what the manifest looks like before the gateway pulls it.

```bash
php artisan microservice:register
```

### microservice:sync

Pulls manifests from all services listed in `manifest.services` config and stores them in the gateway's local Redis.

```bash
php artisan microservice:sync
```

Schedule it to keep manifests fresh:

```php
$schedule->command('microservice:sync')->everyFiveMinutes();
```

## Events

| Event | Trigger |
| --- | --- |
| `RoutesRegistered` | manifest stored via `microservice:register` |
| `ManifestReceived` | gateway successfully pulled and stored a manifest via `microservice:sync` |
| `IdempotentRequestDetected` | response served from idempotency cache |

## Redis Keys

All keys use the prefix from `microservice.redis.prefix` (default `microservice:`).

| Key Pattern | Purpose | TTL |
| --- | --- | --- |
| `{prefix}manifest:{service}` | service manifest JSON | `manifest.ttl` |
| `{prefix}manifests` | set of registered service names | none |
| `{prefix}idempotency:{request_id}` | cached response | `idempotency.ttl` |
| `{prefix}idempotency:{request_id}:lock` | in-flight lock | `idempotency.lock_timeout` |

> [!NOTE]
> Each service has its own Redis. The gateway has its own Redis where it stores manifests pulled from services. There is no shared Redis between services.
