---
title: Gateway
weight: 40
---

## Introduction

The gateway is a role any service in the cluster may take on — there is no separate "gateway" package or process type. A service becomes a gateway simply by pulling route manifests from the services it knows about, storing them in cache, and exposing matching proxy routes in its own application.

Once that's done, inbound requests to the gateway are forwarded to the appropriate downstream service, carrying the original request's headers, body, uploaded files, and authenticated user along with them. This lets you place a single, authenticated public entry point in front of an internal service mesh: clients only ever see the gateway's URL, while requests are routed transparently to whichever service actually owns the resource.

## Configuration

To turn a service into a gateway, add two environment variables:

```env
SERVICE_MANIFEST_SERVICES=oms,pim,sfm
SERVICE_DISCOVERY_PATTERN=http://{service}:8000
```

`SERVICE_MANIFEST_SERVICES` is a comma-separated list of the services whose manifests the gateway should sync. `SERVICE_DISCOVERY_PATTERN` is a URL template in which the `{service}` placeholder is replaced with each service's name — the pattern above suits Docker Compose, while a Kubernetes deployment would typically use something like `http://{service}.default.svc.cluster.local`.

Pulling a manifest is itself a signed request (`GET /microservice/manifest`, protected by `TrustPeer`), so the gateway needs its own key pair and certificate just like any other service — see [Certificates and the Cluster CA](security.md#certificates-and-the-cluster-ca). Downstream services verify the gateway exactly the way they verify any other peer, against the shared `SERVICE_CA_PUBLIC_KEY`. Nothing gateway-specific needs configuring on the service side of that relationship.

## Syncing Manifests

The package ships a `microservice:sync` command that pulls manifests from the configured services and stores them in the cache. You may run it manually, or let the scheduler run it automatically — by default it runs every five minutes:

```bash
php artisan microservice:sync          # sync all configured services
php artisan microservice:sync oms pim  # sync specific services
```

The auto-sync interval is controlled by `SERVICE_MANIFEST_SYNC_INTERVAL` (in minutes); setting it to `0` disables the scheduler entirely, which is useful if you'd rather trigger syncing from your own cron or CI pipeline instead.

Manifests carry no key material at all, so rotating a service's key is entirely independent of syncing — it's just a matter of reissuing that service's certificate (see [Certificates and the Cluster CA](security.md#certificates-and-the-cluster-ca)), and it touches no other service's configuration or manifest.

## Registering Routes

Once manifests are available, you may register all proxy routes in your gateway's `routes/api.php` file with a single call:

```php
use Jurager\Microservice\Gateway\Gateway;

Route::middleware(['auth:sanctum'])->group(function () {
    Gateway::routes();
});
```

`Gateway::routes()` reads every manifest currently in the cache and registers a matching route in your gateway for each one. Wrapping the call in an `auth:sanctum` (or any other authentication) middleware group is enough to secure the entire proxied surface area in one place, since every registered route inherits it.

### Filtering and Prefixing

You won't always want to expose every synced service, or expose it at the same path it uses internally. To register routes for only a subset of services, or to add a URI prefix per service, pass a configuration closure alongside a `services` filter:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('pim')->prefix('catalog');
    $routes->service('oms')->prefix('orders');
}, services: ['pim', 'oms']);
```

With this setup, a call to `/catalog/v1/products` on the gateway is proxied to `pim`'s `/v1/products`, and a call to `/orders/v1/orders` is proxied to `oms`'s `/v1/orders`. Services not listed in `services` are left out of the gateway entirely, even if their manifest is present in the cache.

### Per-Route Middleware

You may attach additional middleware to specific routes within the gateway, the same way you would on any Laravel route:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('oms')
        ->post('/v1/orders')
        ->middleware(['audit']);
});
```

This is useful for gateway-only concerns that shouldn't leak into the downstream service itself — auditing, rate limiting a specific endpoint more aggressively than the rest, or requiring an additional permission check before the request is even proxied.

### Multi-Method Routes

If a downstream route answers several HTTP methods, it's published as a single manifest entry and the gateway registers it as a single route, so it keeps a single name:

```php
// In the downstream service's routes file:
Route::match(['get', 'post'], '/v1/attributes', 'attributeIndex')->name('attributes.index');
```

```
GET|POST  oms/v1/attributes  oms.attributes.index
```

Targeting a route like this from the configuration closure requires `match()`, which applies the override to every method the route answers at once:

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('oms')
        ->match(['GET', 'POST'], '/v1/attributes')
        ->middleware(['audit']);
});
```

> [!WARNING]
> Naming a single method instead — `->post('/v1/attributes')->middleware([...])` — splits the route in two, because the methods no longer share a middleware stack. The gateway registers the remaining methods under the manifest's original name and leaves the split-off route unnamed, since a route name may only be assigned once; reusing it causes `php artisan route:cache` to fail with *"Another route has already been assigned name"*.

## Route Metadata

Metadata defined on a service's route via Laravel's `defaults` mechanism is carried in the manifest and available on the gateway once the route is registered. This lets a downstream service declare permissions, rate-limit tiers, or any other policy data that the gateway can act on without having to duplicate that knowledge in the gateway's own codebase:

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

The `_service` key is always added automatically, regardless of whether the downstream route declared any metadata of its own, and identifies which service the current route proxies to.

## Request Forwarding

When a proxied route is hit, the gateway rebuilds the request against the downstream service using the [client](client.md) and streams the response straight back — nothing is buffered in memory beyond what a single chunk requires.

The JSON body of the incoming request is decoded and forwarded as-is. If the request carries uploaded files (`multipart/form-data`), they are forwarded as a proper multipart request instead, file streams included — you don't need to handle uploads differently just because they're passing through a gateway. The query string is forwarded unchanged, and so is the `X-Request-Id` header, which keeps [idempotency](security.md#idempotency) working end-to-end across the proxy hop.

A handful of headers are added to every proxied request so the downstream service can reconstruct the original client-facing context:

```
X-Forwarded-Host:   the gateway's own host
X-Forwarded-Proto:  http or https, as seen by the gateway
X-Forwarded-Port:   the gateway's port
X-Gateway-Base-Url: the full URL the client actually called
X-Forwarded-Prefix: the service's configured prefix, if one was set (see Filtering and Prefixing)
```

On the way back, a small set of response headers is stripped before the response reaches the client — `Transfer-Encoding` and `Connection` always, plus whatever is listed in `microservice.proxy.strip_headers`, which defaults to the CORS response headers (`Access-Control-Allow-Origin` and friends). The downstream service's own CORS configuration, if any, is not meant to leak through a gateway that applies its own.

## Health & Observability

A gateway exposes four read-only endpoints, each independently toggleable via config and each serving a different consumer:

```env
SERVICE_HEALTH_ENDPOINT=/microservice/health                    # detailed report — dashboards, humans
SERVICE_HEALTH_LIVENESS_ENDPOINT=/microservice/health/live       # is the process up? — orchestrator liveness probe
SERVICE_HEALTH_READINESS_ENDPOINT=/microservice/health/ready     # can it serve traffic? — orchestrator readiness probe
SERVICE_HEALTH_METRICS_ENDPOINT=/microservice/metrics            # the same report, as Prometheus text exposition
```

Setting any of these to an empty value disables that endpoint. Liveness answers instantly and never touches Redis or RabbitMQ, so it's safe to probe as often as your orchestrator likes. Readiness checks that Redis is reachable and that at least one manifest is loaded — cheap enough for a frequent probe, but still meaningful, since a gateway that can't see Redis can't route anything.

The detailed report is the expensive one — it also opens a RabbitMQ connection to check dead-letter depth — so it's cached for `SERVICE_HEALTH_CACHE_TTL` seconds (15 by default) to keep frequent scrapes from hammering the broker:

```json
{
  "status": "degraded",
  "gateway": "gateway-admin",
  "instance": { "hostname": "gateway-admin-7f9c", "version": "3.4.0", "environment": "production", "uptime_seconds": 41200 },
  "checked_at": "2026-05-21T10:30:00+00:00",
  "summary": { "total": 3, "healthy": 2, "missing": 1 },
  "dependencies": {
    "cache": { "status": "up", "latency_ms": 1.2 },
    "rabbitmq": { "status": "up", "latency_ms": 8.4, "dead_letters": { "gateway-admin.sfm.site.config.dlq": 0 } }
  },
  "services": {
    "oms": { "status": "healthy", "synced_at": "2026-05-21T10:28:00+00:00", "routes_count": 12, "circuit": { "state": "closed", "failures": 0, "threshold": 5 } },
    "pim": { "status": "healthy", "synced_at": "2026-05-21T10:29:00+00:00", "routes_count": 34 },
    "sfm": { "status": "missing", "routes_count": 0 }
  }
}
```

`status` is derived, never set directly, from three inputs: any critical dependency being down (Redis) marks the gateway `unhealthy`; any service manifest being `missing` also marks it `unhealthy`; an open circuit breaker, a non-critical dependency being down (RabbitMQ), or any dead-letter queue holding messages marks it `degraded` instead. Anything short of that is `healthy`. `unhealthy` is the only status that answers with HTTP `503` — `degraded` still answers `200`, since the gateway is serving traffic, just imperfectly.

By default the report omits infrastructure details like base URLs and configured timeouts, so it's safe to expose without leaking your internal topology. Pass `?verbose=1`, or set `SERVICE_HEALTH_VERBOSE=true` to make it the default, to include them. `?service=oms` narrows the report to a single service.

The metrics endpoint renders the same data as Prometheus gauges instead of JSON, ready to scrape directly:

```
# HELP microservice_up Overall gateway status (1=healthy, 0.5=degraded, 0=unhealthy).
# TYPE microservice_up gauge
microservice_up 0.5
# HELP microservice_service_up Service manifest availability (1=present, 0=missing).
# TYPE microservice_service_up gauge
microservice_service_up{service="oms"} 1
microservice_service_up{service="sfm"} 0
# HELP microservice_dead_letter_messages Messages sitting in each dead-letter queue.
# TYPE microservice_dead_letter_messages gauge
microservice_dead_letter_messages{queue="gateway-admin.sfm.site.config.dlq"} 0
```

> [!NOTE]
> All four endpoints are public by default, since an authenticated probe is rarely practical for an orchestrator. If the service list a report exposes is more than you want unauthenticated callers to see, protect the endpoint at the route level or keep `verbose` off.
