---
title: Gateway
weight: 40
---

## Introduction

The gateway is a special role any service may take on. A gateway pulls route manifests from the services it knows about, stores them in Cache, and exposes them as proxied routes in its own application. Inbound requests to the gateway are forwarded to the appropriate service with the original request's headers, body, and authenticated user attached.

This lets you place a single, authenticated public entry point in front of an internal service mesh — clients see one URL while requests are routed transparently to the right downstream service.

## Configuration

To turn a service into a gateway, you should add two environment variables alongside the standard `SERVICE_NAME` and `SERVICE_SECRET`:

```env
SERVICE_MANIFEST_SERVICES=oms,pim,sfm
SERVICE_DISCOVERY_PATTERN=http://{service}:8000
```

`SERVICE_MANIFEST_SERVICES` is a comma-separated list of services whose manifests should be synced. `SERVICE_DISCOVERY_PATTERN` is a URL template in which `{service}` is replaced with each name — the example above suits Docker Compose, while a Kubernetes deployment might use `http://{service}.default.svc.cluster.local`.

## Syncing Manifests

The package ships a `microservice:sync` command that pulls manifests from configured services and stores them in Cache. You may run it manually or let the scheduler run it automatically — by default it runs every five minutes:

```bash
php artisan microservice:sync          # sync all configured services
php artisan microservice:sync oms pim  # sync specific services
```

The auto-sync interval is controlled by `SERVICE_MANIFEST_SYNC_INTERVAL` (in minutes). Setting it to `0` disables the scheduler entirely — useful when you want to manage syncing externally.

## Registering Routes

Once manifests are available, you may register all proxy routes in your gateway's `routes/api.php` file with a single call:

```php
use Jurager\Microservice\Gateway\Gateway;

Route::middleware(['auth:sanctum'])->group(function () {
    Gateway::routes();
});
```

`Gateway::routes()` reads every manifest from Cache and registers a matching route in your gateway. Wrapping the call in an `auth:sanctum` (or any other authentication) middleware group secures the entire surface area in one place.

### Filtering and Prefixing

To register routes for only a subset of services, or to add a URI prefix per service, you may pass a configuration closure and a `services` filter:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('pim')->prefix('catalog');
    $routes->service('oms')->prefix('orders');
}, services: ['pim', 'oms']);
```

With this setup, calls to `/catalog/v1/products` are proxied to `pim`, and calls to `/orders/v1/orders` are proxied to `oms`.

### Per-Route Middleware

You may attach additional middleware to specific routes within the gateway:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('oms')
        ->post('/v1/orders')
        ->middleware(['audit']);
});
```

## Route Metadata

Metadata defined on a service's route via Laravel's `defaults` mechanism is included in the manifest and available on the gateway. This lets services declare permissions, rate-limit tiers, or any other policy data that the gateway can act on:

```php
// In the downstream service's routes file:
Route::post('/v1/orders', OrderController::class)
    ->defaults('permissions', ['orders.write']);
```

```php
// On the gateway, inside a controller or middleware:
$request->route()->getAction('permissions'); // ['orders.write']
$request->route()->getAction('_service');    // 'oms'
```

The `_service` key is always added automatically and identifies the downstream service the route proxies to.

## Health Endpoint

The gateway exposes a health endpoint at `/microservice/health` that summarizes the freshness of every cached manifest:

```json
{
  "gateway": "gateway-admin",
  "services": {
    "oms": { "status": "ok",      "routes_count": 12, "expires_in": 243 },
    "pim": { "status": "missing" }
  }
}
```

Each service reports one of three statuses:

| Status | Meaning |
|---|---|
| `ok` | Manifest is present and fresh (more than 50% TTL remaining) |
| `stale` | Manifest is present but less than 50% TTL remains |
| `missing` | No manifest found in Cache |

The endpoint is public by default — you should protect it at the route level if it leaks information you'd rather not expose.
