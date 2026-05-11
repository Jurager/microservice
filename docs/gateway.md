---
title: Gateway
weight: 40
---

# Gateway

## Setup

List the services to sync on the gateway:

```env
SERVICE_MANIFEST_SERVICES=oms,pim,sfm
SERVICE_DISCOVERY_PATTERN=http://{service}:8000
```

Sync manifests manually or let the scheduler run automatically (default: every 5 minutes):

```bash
php artisan microservice:sync          # all configured services
php artisan microservice:sync oms pim  # specific services
```

## Registering Routes

In your gateway's route file:

```php
use Jurager\Microservice\Gateway\Gateway;

Route::middleware(['auth:sanctum'])->group(function () {
    Gateway::routes();
});
```

Filter to specific services or add a URI prefix:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('pim')->prefix('catalog');
    $routes->service('oms')->prefix('orders');
}, services: ['pim', 'oms']);
```

Add middleware to a specific route:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('oms')
        ->post('/v1/orders')
        ->middleware(['audit']);
});
```

## Route Metadata

Metadata defined on a microservice route is included in the manifest and available on the gateway:

```php
// service route
Route::post('/v1/orders', OrderController::class)
    ->defaults('permissions', ['orders.write']);

// gateway
$request->route()->getAction('permissions'); // ['orders.write']
$request->route()->getAction('_service');    // 'oms'
```

## Health Endpoint

Available at `/microservice/health`:

```json
{
  "gateway": "gateway-admin",
  "services": {
    "oms": { "status": "ok", "routes_count": 12, "expires_in": 243 },
    "pim": { "status": "missing" }
  }
}
```

| Status | Meaning |
|---|---|
| `ok` | Manifest is present and fresh |
| `stale` | Less than 50% TTL remains |
| `missing` | No manifest found in Redis |

> [!NOTE]
> The health endpoint is public by default. Protect it at the route level if needed.
