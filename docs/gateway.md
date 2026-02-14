# Gateway and Discovery

Gateway mode uses manifests to proxy routes to services.

## Register Manifest

Each service builds and registers its manifest:

```bash
php artisan microservice:register
```

If `manifest.gateway` is set, the manifest is pushed to that service at `POST /microservice/manifest`.
If `manifest.gateway` is `null`, the manifest is stored in Redis.

> [!WARNING]
> Do not run `microservice:register` on the gateway service.

> [!NOTE]
> The command refuses to register a manifest if the current service appears to be the gateway.

## Gateway Routes

```php
use Jurager\Microservice\Gateway\Gateway;

Route::middleware(['auth:sanctum'])->group(function () {
    Gateway::routes();
});
```

Filter by services:

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

Override a specific route:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('oms')
        ->post('/api/orders')
        ->middleware(['audit']);
});
```

## Route Metadata

Manifest routes include custom metadata from your service routes. You can access it on gateway routes:

```php
$request->route()->getAction('permissions');
$request->route()->getAction('_service');
```

## Manifest Endpoint

The gateway exposes:

- `POST /microservice/manifest` (protected by `TrustService`)

If route caching is enabled, the gateway rebuilds cache after a manifest is received.

> [!NOTE]
> Gateway routes automatically include the `Idempotency` middleware.

## Proxy Behavior

`ProxyController` forwards:

- Method, path, query, and JSON body
- `X-Forwarded-*` headers

It preserves status and headers, but strips `Transfer-Encoding`, `Connection`, and headers from `microservice.proxy.strip_headers`.

> [!NOTE]
> Only JSON request bodies are forwarded. Non-JSON bodies are ignored.

## Trusted Proxies

When `manifest.gateway` is set on a service, the package trusts all proxies to generate correct URLs.
