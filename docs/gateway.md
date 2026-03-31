---
title: Gateway
weight: 40
---

# Gateway

## How Discovery Works

```
microservice:sync  →  GET http://oms/microservice/manifest  →  store in Redis
                   →  GET http://pim/microservice/manifest  →  store in Redis

Gateway::routes()  →  read all manifests from Redis
                   →  register proxy routes → ProxyController
```

The gateway pulls; services just expose a manifest endpoint protected by `TrustService`.

## Configuring Services

On the gateway, list the services to sync:

```env
SERVICE_MANIFEST_SERVICES=oms,pim,agm
```

Or in `config/microservice.php`:

```php
'manifest' => [
    'services' => ['oms', 'pim', 'agm'],
],
```

## Syncing Manifests

```bash
php artisan microservice:sync          # all configured services
php artisan microservice:sync oms pim  # specific services only
```

The schedule is registered automatically based on `manifest.sync_interval` (default: 5 minutes). No manual scheduling is needed.

```env
SERVICE_MANIFEST_SYNC_INTERVAL=10  # change interval
SERVICE_MANIFEST_SYNC_INTERVAL=0   # disable auto-sync
```

> [!NOTE]
> `sync_interval` should be shorter than `manifest.ttl` (default 300 s) to prevent manifests from expiring between syncs.

## Registering Gateway Routes

In your gateway's route file:

```php
use Jurager\Microservice\Gateway\Gateway;

Route::middleware(['auth:sanctum'])->group(function () {
    Gateway::routes();
});
```

Filter to specific services:

```php
Gateway::routes(services: ['pim', 'oms']);
```

## Route Overrides

Apply a URI prefix or custom middleware per service:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('pim')->prefix('catalog');
    $routes->service('oms')->prefix('orders');
});
```

Override a specific route with extra middleware:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('oms')
        ->post('/api/orders')
        ->middleware(['audit']);
});
```

## Route Metadata

Metadata defined on a microservice route is included in the manifest and available on the gateway:

```php
// microservice (e.g. oms) route definition
Route::post('/api/orders', OrderController::class)
    ->defaults('permissions', ['orders.write']);

// on the gateway
$request->route()->getAction('permissions'); // ['orders.write']
$request->route()->getAction('_service');    // 'oms'
```

## Proxy Behavior

`ProxyController` forwards: method, path, query string, JSON body, and `X-Forwarded-*` headers. It preserves status code and response headers, stripping `Transfer-Encoding`, `Connection`, and anything in `microservice.proxy.strip_headers`.

> [!NOTE]
> Only JSON request bodies are forwarded. Non-JSON bodies are ignored.

## Health Endpoint

Available at `/microservice/health` (override via `SERVICE_HEALTH_ENDPOINT`):

```json
{
  "gateway": "gateway-admin",
  "services": {
    "oms": {
      "status": "ok",
      "synced_at": "2026-03-02T10:00:00+00:00",
      "expires_in": 243,
      "routes_count": 12,
      "base_url": "http://oms:8000",
      "timeout": 5
    },
    "pim": {
      "status": "missing",
      "synced_at": null,
      "routes_count": 0,
      "base_url": null,
      "timeout": null
    }
  }
}
```

| Status | Meaning |
|---|---|
| `ok` | Manifest is present and fresh |
| `stale` | Less than 50% TTL remains — sync may be lagging |
| `missing` | Service is configured but no manifest found in Redis |

> [!NOTE]
> The health endpoint is public by default. Protect it at the route level if needed.

## Events

| Event | Dispatched when |
|---|---|
| `RoutesRegistered` | `/microservice/manifest` endpoint is called on a service |
| `ManifestReceived` | Gateway successfully pulled and stored a manifest |
| `IdempotentRequestDetected` | Response served from idempotency cache |

## Redis Keys

All keys use the prefix from `microservice.redis.prefix` (default `microservice:`).

| Key | Purpose | TTL |
|---|---|---|
| `{prefix}manifest:{service}` | Manifest JSON | `manifest.ttl` |
| `{prefix}manifests` | Set of registered service names | none |
| `{prefix}idempotency:{request_id}` | Cached response | `idempotency.ttl` |
| `{prefix}idempotency:{request_id}:lock` | In-flight lock | `idempotency.lock_timeout` |
