---
title: Gateway
weight: 60
---

# Gateway and Discovery

Gateway mode uses pull-based manifest sync to proxy routes to services. The gateway periodically fetches route manifests from each configured service and registers them as proxy routes.

## How It Works

```
microservice:sync (scheduler on gateway)
  → GET http://oms/microservice/manifest    → store in Redis
  → GET http://pim/microservice/manifest    → store in Redis
  → GET http://agm/microservice/manifest    → store in Redis

Gateway::routes()
  → reads all manifests from Redis
  → registers proxy routes pointing to ProxyController
```

## Configuring Services to Sync

On the gateway, list the services to pull from:

```php
// config/microservice.php
'manifest' => [
    'services' => ['oms', 'pim', 'agm'],
],
```

The gateway uses `SERVICE_DISCOVERY_PATTERN` or manifest `base_url` to locate each service.

## Running the Sync

Run manually:

```bash
php artisan microservice:sync
```

Sync specific services only:

```bash
php artisan microservice:sync oms pim
```

The package registers the schedule automatically based on `manifest.sync_interval` (default: 5 minutes). No manual scheduling is needed.

To change the interval:

```env
SERVICE_MANIFEST_SYNC_INTERVAL=10
```

Set to `0` to disable automatic scheduling entirely.

> [!NOTE]
> The sync interval should be shorter than `manifest.ttl` (default 300 seconds) to prevent manifests from expiring between syncs.

## Manifest Endpoint

Each service automatically exposes:

```
GET /microservice/manifest
```

Protected by `TrustService` middleware — only signed requests are accepted.

This endpoint returns the service manifest: its routes, base URLs, and timeout.

## Gateway Routes

Register proxy routes in your gateway's route file:

```php
use Jurager\Microservice\Gateway\Gateway;

Route::middleware(['auth:sanctum'])->group(function () {
    Gateway::routes();
});
```

Filter by specific services:

```php
Gateway::routes(services: ['pim', 'oms']);
```

## Prefixes and Overrides

```php
use Jurager\Microservice\Gateway\Gateway;
use Jurager\Microservice\Gateway\GatewayRoutes;

Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('pim')->prefix('catalog');
    $routes->service('oms')->prefix('orders');
});
```

Override a specific route with custom middleware:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('oms')
        ->post('/api/orders')
        ->middleware(['audit']);
});
```

## Route Metadata

Routes can carry custom metadata defined in the microservice. It is included in the manifest and available on the gateway:

```php
// microservice route definition
Route::post('/api/orders', OrderController::class)
    ->name('orders.store')
    ->defaults('permissions', ['orders.write']);

// on the gateway
$request->route()->getAction('permissions'); // ['orders.write']
$request->route()->getAction('_service');    // 'oms'
```

## Health Endpoint

The health endpoint is enabled by default at `/microservice/health`. Override via `SERVICE_HEALTH_ENDPOINT`:

```env

```

Example response:

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

Service statuses:

| Status | Meaning |
| --- | --- |
| `ok` | Manifest is present and fresh |
| `stale` | Manifest exists but less than 50% TTL remains — sync may be lagging |
| `missing` | Service is configured but no manifest found in Redis |

> [!NOTE]
> The health endpoint is public by default. Protect it at the route level if needed.

## Proxy Behavior

`ProxyController` forwards:

- Method, path, query string, and JSON body
- `X-Forwarded-Host`, `X-Forwarded-Proto`, `X-Forwarded-Port`, `X-Forwarded-Prefix`

It preserves status code and response headers, but strips `Transfer-Encoding`, `Connection`, and any headers listed in `microservice.proxy.strip_headers`.

> [!NOTE]
> Only JSON request bodies are forwarded. Non-JSON bodies are ignored.

> [!NOTE]
> Gateway routes automatically include the `Idempotency` middleware.
