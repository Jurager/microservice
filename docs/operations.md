---
title: Operations
weight: 70
---

# Operations

## Artisan Commands

| Command | Where | Description |
| --- | --- | --- |
| `microservice:sync` | Gateway | Pull manifests from all configured services and store in gateway Redis |
| `microservice:sync oms pim` | Gateway | Pull manifests from specific services only |

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
| `RoutesRegistered` | manifest endpoint called on a service |
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
