---
title: Installation
weight: 10
---

# Installation

```bash
composer require jurager/microservice
```

Then publish the configuration

```bash
php artisan vendor:publish --tag=microservice-config
```


## Environment

Minimum required on every service:

```env
SERVICE_NAME=oms
SERVICE_SECRET=base64-generated-secret
SERVICE_REDIS_CONNECTION=default
```

Additional on a **gateway**:

```env
SERVICE_NAME=gateway-admin
SERVICE_DISCOVERY_PATTERN=http://{service}:8000
SERVICE_MANIFEST_SERVICES=oms,pim,agm
```

Generate a shared secret:

```bash
openssl rand -base64 32
```

> [!WARNING]
> All services in the cluster must share the same `SERVICE_SECRET`. Mismatched secrets cause all HMAC verifications to fail.

> [!WARNING]
> Never set `SERVICE_DEBUG=true` in production — it disables all signature verification.

## Configuration Reference

Configuration lives in `config/microservice.php`.

### Core

| Key | Env | Default | Description |
|---|---|---|---|
| `name` | `SERVICE_NAME` | `app` | Unique service identifier used in signatures and manifests |
| `secret` | `SERVICE_SECRET` | — | Shared HMAC secret |
| `debug` | `SERVICE_DEBUG` | `false` | Disables signature verification — local dev only |
| `algorithm` | — | `sha256` | HMAC algorithm |
| `timestamp_tolerance` | — | `60` | Maximum allowed request age in seconds |

### Redis

```php
'redis' => [
    'connection' => env('SERVICE_REDIS_CONNECTION', 'default'),
    'prefix'     => 'microservice:',
],
```

Each service uses its own Redis connection. The gateway uses a separate Redis instance shared across all its pods.

### Service Discovery

```php
'discovery' => [
    'pattern' => env('SERVICE_DISCOVERY_PATTERN'),
],
```

When set, service URLs are resolved by substituting `{service}` in the pattern:

```env
# Docker Compose
SERVICE_DISCOVERY_PATTERN=http://{service}:8000

# Kubernetes
SERVICE_DISCOVERY_PATTERN=http://{service}.default.svc.cluster.local
```

When `null`, the base URL is read from the service's manifest stored in Redis by `microservice:sync`.

### Manifest

| Key | Env | Default | Description |
|---|---|---|---|
| `timeout` | `SERVICE_TIMEOUT` | `30` | HTTP timeout (seconds) published in the manifest for callers |
| `ttl` | `SERVICE_MANIFEST_TTL` | `300` | How long the manifest lives in Redis (seconds) |
| `prefix` | `SERVICE_MANIFEST_PREFIX` | `api` | Only routes matching these URI prefixes are included. Comma-separated or array. Empty = all routes |
| `services` | `SERVICE_MANIFEST_SERVICES` | — | **Gateway only.** Comma-separated list of service names to sync |
| `sync_interval` | `SERVICE_MANIFEST_SYNC_INTERVAL` | `5` | **Gateway only.** Auto-sync interval in minutes. `0` disables it |

> [!NOTE]
> `HEAD` routes and routes not matching `manifest.prefix` are excluded from the manifest.

> [!NOTE]
> `sync_interval` should be shorter than `ttl` so manifests don't expire between syncs.

### Health Endpoint

```php
'health' => [
    'endpoint' => env('SERVICE_HEALTH_ENDPOINT', '/microservice/health'),
],
```

**Gateway only.** Exposes a JSON status page for all configured services. Set to `null` or empty to disable.

> [!NOTE]
> The health endpoint is public by default. Protect it at the route level if needed.

### Idempotency

| Key | Env | Default | Description |
|---|---|---|---|
| `idempotency.ttl` | `SERVICE_IDEMPOTENCY_TTL` | `86400` | How long cached responses are kept (seconds) |
| `idempotency.lock_timeout` | — | `10` | Lock duration in seconds for in-flight deduplication |

> [!WARNING]
> `lock_timeout` must be longer than your slowest request. If the lock expires before the request completes, a duplicate request may be processed concurrently.

### Proxy

```php
'proxy' => [
    'strip_headers' => [
        'Access-Control-Allow-Origin',
        // …
    ],
],
```

Headers removed from proxied responses to prevent conflicts with gateway-level CORS headers.

## Running Tests

```bash
composer test
```
