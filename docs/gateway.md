---
title: Setting up a gateway
weight: 40
---

A gateway exposes routes from other services and forwards requests to them.

## Configuration

To turn a service into a gateway, add two environment variables:

```env
SERVICE_MANIFEST_SERVICES=catalog,orders
SERVICE_DISCOVERY_PATTERN=http://{service}:8000
```

List services to synchronize and set a URL pattern containing `{service}`.

### Peers

Declare services outside the discovery network as peers:

```php
// config/microservice.php
return [
    'peers' => [
        'api' => env('API_URL'),
    ],
];
```

Peer URLs take precedence over discovery. `microservice:sync` includes peers automatically; leave them out of `SERVICE_MANIFEST_SERVICES`.

Peer URLs must use HTTP or HTTPS. Empty URLs are skipped. A peer cannot also appear in `manifest.services`.

Manifest requests use the same signing credentials as other service requests. See [Security](security.md#how-peer-trust-works).

## Syncing manifests

Synchronize manifests manually or through Laravel's scheduler, every five minutes by default:

```bash
php artisan microservice:sync          # sync all configured services
php artisan microservice:sync catalog orders  # sync specific services
```

Set `SERVICE_MANIFEST_SYNC_INTERVAL` in minutes, or `0` to disable scheduling.

Each gateway instance refreshes its route cache or reloads Octane when routes change. Run the Laravel scheduler on each instance.

## Registering routes

Register proxy routes in your gateway's `routes/api.php`:

```php
use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Gateway\Gateway;
use Jurager\Microservice\Gateway\GatewayRoutes;

Route::middleware(['auth:sanctum'])->group(function () {
    Gateway::routes();
});
```

Registered proxy routes inherit the surrounding middleware.

### Filtering and prefixing

Filter services and customize their URL prefixes:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('catalog')->prefix('catalog');
    $routes->service('orders')->prefix('orders');
}, services: ['catalog', 'orders']);
```

For example, `/catalog/v1/products` forwards to the `catalog` service's `/v1/products`.

### Per-Route middleware

You may attach additional middleware to specific routes within the gateway, the same way you would on any Laravel route:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('orders')
        ->post('/v1/orders')
        ->middleware(['audit']);
});
```

### Multi-Method routes

Use `match()` to apply the same middleware to every method of a multi-method route:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('orders')
        ->match(['GET', 'POST'], '/v1/attributes')
        ->middleware(['audit']);
});
```

Applying middleware to one method splits the route. Only the remaining methods retain the original route name.

## Route metadata

Route defaults are included in manifests and exposed as gateway route actions:

```php
// In the downstream service's routes file:
Route::post('/v1/orders', OrderController::class)
    ->defaults('permissions', ['orders.write']);
```

```php
// On the gateway, inside a controller or middleware:
$request->route()->getAction('permissions'); // ['orders.write']
$request->route()->getAction('_service');    // 'orders'
```

The `_service` action identifies the downstream service.

## Request forwarding

The gateway forwards the body, query parameters, uploaded files, and `X-Request-Id`.

Responses retain their status, body, and headers. Upstream `401`, `407`, and `5xx` responses become `502` without upstream error details.

Apply [Idempotency](security.md#idempotency) on the services that handle writes.

`Accept-Language` is forwarded by default. Add headers your downstream services need:

```php
'proxy' => [
    'forward_headers' => ['Accept-Language', 'Authorization'],
],
```

Forwarded host, scheme, port, and gateway URL headers let downstream services build client-facing links.

Connection headers and configured `proxy.strip_headers` are removed from responses. The default list removes downstream CORS headers.

## Health & observability

See [checking service health](health.md) for probes, reports, metrics, and custom dependency checks.
