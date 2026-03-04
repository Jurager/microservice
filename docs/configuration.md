---
title: Configuration
weight: 30
---

# Configuration

Configuration is stored in `config/microservice.php`.

## Core Settings

```php
'name' => env('SERVICE_NAME', 'app'),
'debug' => env('SERVICE_DEBUG', false),
'secret' => env('SERVICE_SECRET', ''),
'algorithm' => 'sha256',
'timestamp_tolerance' => 60,
```

- `name` — unique service identifier used in signatures and manifests.
- `debug` — disables signature verification in `TrustGateway`. Local development only.
- `secret` — shared HMAC secret. Must be identical on all services.
- `timestamp_tolerance` — maximum allowed request age in seconds.

> [!WARNING]
> Never enable `debug` in production.

## Redis

```php
'redis' => [
    'connection' => env('SERVICE_REDIS_CONNECTION', 'default'),
    'prefix' => 'microservice:',
],
```

Each service uses its own Redis connection. The gateway uses a separate Redis instance shared across its pods.

## Service Discovery

```php
'discovery' => [
    'pattern' => env('SERVICE_DISCOVERY_PATTERN'),
],
```

When `pattern` is set, service base URLs are resolved by substituting `{service}` in the pattern. This is the recommended approach.

```env
# Docker Compose
SERVICE_DISCOVERY_PATTERN=http://{service}:8000

# Kubernetes
SERVICE_DISCOVERY_PATTERN=http://{service}.default.svc.cluster.local
```

When `pattern` is `null`, the base URL is read from the service manifest stored in the gateway's local Redis (populated via `microservice:sync`).

> [!NOTE]
> DNS-based discovery delegates all routing and load balancing to the infrastructure. No URL configuration is needed per service.

## Manifest

Settings that every service publishes about itself:

```php
'manifest' => [
    'timeout'       => env('SERVICE_TIMEOUT', 30),
    'ttl'           => env('SERVICE_MANIFEST_TTL', 300),
    'prefix'        => env('SERVICE_MANIFEST_PREFIX', 'api'),
    'services'      => env('SERVICE_MANIFEST_SERVICES', ''),
    'sync_interval' => env('SERVICE_MANIFEST_SYNC_INTERVAL', 5),
],
```

- `timeout` — HTTP timeout in seconds for callers of this service. Published in the manifest so gateways use it automatically.
- `ttl` — how long the manifest lives in Redis before expiring (seconds). Override via `SERVICE_MANIFEST_TTL`.
- `prefix` — only routes matching these URI prefixes are included in the manifest. Accepts a comma-separated string (`v1,v2`) or an array. Empty value includes all routes. Override via `SERVICE_MANIFEST_PREFIX`.
- `services` — **gateway-only**. Comma-separated list of service names to pull manifests from. Override via `SERVICE_MANIFEST_SERVICES=oms,pim,agm`. Parsed into an array at boot time.
- `sync_interval` — **gateway-only**. How often (in minutes) to automatically run `microservice:sync`. Set to `0` to disable. Override via `SERVICE_MANIFEST_SYNC_INTERVAL`.

> [!NOTE]
> `HEAD` routes are excluded from the manifest.

> [!NOTE]
> Only routes matching `manifest.prefix` are included.

## Health Endpoint

```php
'health' => [
    'endpoint' => env('SERVICE_HEALTH_ENDPOINT', '/microservice/health'),
],
```

**Gateway-only.** When set, exposes a health route at the given URI showing sync status for all configured services.



> [!NOTE]
> The health endpoint is public by default. Protect it at the route level if needed.

## Idempotency

```php
'idempotency' => [
    'ttl'          => env('SERVICE_IDEMPOTENCY_TTL', 86400),  // 24 hours
    'lock_timeout' => 10,
],
```

Caches successful responses by `X-Request-Id` to prevent duplicate processing. Override TTL via `SERVICE_IDEMPOTENCY_TTL`.

## Proxy

```php
'proxy' => [
    'strip_headers' => [
        'Access-Control-Allow-Origin',
        'Access-Control-Allow-Methods',
        'Access-Control-Allow-Headers',
        'Access-Control-Allow-Credentials',
        'Access-Control-Expose-Headers',
        'Access-Control-Max-Age',
    ],
],
```

Headers removed from proxied responses to prevent conflicts with gateway-level CORS headers.

