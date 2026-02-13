# Jurager/Microservice

[![Latest Stable Version](https://poser.pugx.org/jurager/microservice/v/stable)](https://packagist.org/packages/jurager/microservice)
[![Total Downloads](https://poser.pugx.org/jurager/microservice/downloads)](https://packagist.org/packages/jurager/microservice)
[![PHP Version Require](https://poser.pugx.org/jurager/microservice/require/php)](https://packagist.org/packages/jurager/microservice)
[![License](https://poser.pugx.org/jurager/microservice/license)](https://packagist.org/packages/jurager/microservice)

A Laravel package for secure and resilient HTTP communication between microservices.

Features:

- HMAC-signed requests for internal service authentication
- Automatic retries and failover across multiple instances
- Redis-based health tracking to avoid unhealthy nodes
- Route discovery for gateway proxying
- Idempotency support for non-safe requests (POST, PUT, PATCH)
- Built for production environments where reliability and consistency matter.

> [!NOTE]
> The documentation for this package is currently being written. For now, please refer to this readme for information on the functionality and usage of the package.

- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Client API](#client-api)
- [Security and Idempotency](#security-and-idempotency)
- [Gateway and Discovery](#gateway-and-discovery)
- [Operations](#operations)
- [Testing](#testing)
- [License](#license)

## Requirements

- PHP 8.2+
- Laravel 11+
- Redis
- Guzzle 7+

## Quick Start

Install the package:

```bash
composer require jurager/microservice
```

Publish config:

```bash
php artisan vendor:publish --tag=microservice-config
```

Generate secret:

```bash
openssl rand -base64 32
```

Set env vars (minimum):

```dotenv
SERVICE_NAME=oms
SERVICE_SECRET=base64-generated-secret
SERVICE_REDIS_CONNECTION=default
```

Define target services in `config/microservice.php`:

```php
'services' => [
    'pim' => [
        'base_urls' => ['http://pim-1:8000', 'http://pim-2:8000'],
    ],
],
```

Send your first request:

```php
use Jurager\Microservice\Client\ServiceClient;

$response = app(ServiceClient::class)
    ->service('pim')
    ->get('/api/products/42')
    ->send()
    ->throw();

$product = $response->json('data');
```

## Configuration

Main file: `config/microservice.php`.

### Core options

```php
'name' => env('SERVICE_NAME', 'app'),
'debug' => env('SERVICE_DEBUG', false),
'secret' => env('SERVICE_SECRET', ''),
'algorithm' => 'sha256',
'timestamp_tolerance' => 60,
```

- `name`: unique service name
- `debug`: skips signature verification in `TrustGateway` (use only locally)
- `secret`: shared secret for all services in the cluster
- `timestamp_tolerance`: max signature age in seconds

### Services registry

```php
'services' => [
    'oms' => [
        'base_urls' => ['http://oms-1:8000', 'http://oms-2:8000'],
        'timeout' => 5,
        'retries' => 2,
    ],
],
```

- `base_urls`: service instances used for failover
- per-service `timeout` and `retries` override defaults

### Defaults and Redis

```php
'defaults' => [
    'timeout' => 5,
    'retries' => 2,
    'retry_delay' => 100,
],

'redis' => [
    'connection' => env('SERVICE_REDIS_CONNECTION', 'default'),
    'prefix' => 'microservice:',
],
```

### Health

```php
'health' => [
    'endpoint' => env('SERVICE_HEALTH_ENDPOINT'),
    'failure_threshold' => 3,
    'recovery_timeout' => 30,
],
```

### Manifest (Route Discovery)

```php
'manifest' => [
    'ttl' => 300,
    'prefix' => 'api',
    'gateway' => env('MANIFEST_GATEWAY_SERVICE'),
],
```

### Idempotency settings

```php
'idempotency' => [
    'ttl' => 86400,
    'lock_timeout' => 10,
],
```

## Client API

### Basic methods

```php
$client = app(\Jurager\Microservice\Client\ServiceClient::class);

$client->service('oms')->get('/api/orders/1')->send();
$client->service('oms')->post('/api/orders', ['sku' => 'A1'])->send();
$client->service('oms')->put('/api/orders/1', ['status' => 'paid'])->send();
$client->service('oms')->patch('/api/orders/1', ['status' => 'done'])->send();
$client->service('oms')->delete('/api/orders/1')->send();
```

### Request builder

| Method | Purpose |
|---|---|
| `withHeaders(array)` | add headers |
| `withQuery(array)` | add query params |
| `withBody(array)` | replace body |
| `timeout(int)` | override timeout |
| `retries(int)` | override retries |
| `send()` | execute request |

Priority for `timeout` and `retries`:
1. request override (`timeout()`, `retries()`)
2. per-service config
3. `defaults`

### ServiceResponse

```php
$response->status();
$response->ok();
$response->failed();
$response->json();
$response->json('data.id');
$response->json('data.id', null);
$response->body();
$response->header('X-Total');
$response->headers();
$response->toPsrResponse();
$response->throw();
```

### Exceptions

| Exception | Meaning |
|---|---|
| `ServiceUnavailableException` | no healthy/working instance left |
| `ServiceRequestException` | non-2xx response after `->throw()` |
| `InvalidRequestIdException` | invalid `X-Request-Id` in idempotency middleware |
| `DuplicateRequestException` | same request id is being processed now |
| `InvalidCacheStateException` | cached idempotency payload is corrupted |

### Retry and failover behavior

- 5xx and network errors: retry, then switch to next instance
- 4xx: returned immediately (no retry)
- if all healthy instances fail, client tries full instance list once more
- if all attempts fail, throws `ServiceUnavailableException`

## Security and Idempotency

### HMAC signature

Every `ServiceClient` request includes:
- `X-Signature`
- `X-Timestamp`
- `X-Service-Name`
- `X-Request-Id`

Signature format:

```text
$payload   = "{METHOD}\n{PATH}\n{TIMESTAMP}\n{BODY}"
$signature = hash_hmac('sha256', payload, SERVICE_SECRET)
```

### TrustGateway middleware

Use for routes that accept calls from gateway:

```php
use Jurager\Microservice\Http\Middleware\TrustGateway;

Route::middleware(TrustGateway::class)->group(function () {
    Route::apiResource('products', ProductController::class);
});
```

Requires `X-Signature` and `X-Timestamp`, otherwise returns `401`.

### TrustService middleware

`TrustService` extends `TrustGateway` and also requires `X-Service-Name`.
Use it for direct internal service-to-service routes.

```php
use Jurager\Microservice\Http\Middleware\TrustService;

Route::middleware(TrustService::class)->group(function () {
    Route::post('/internal/sync', [SyncController::class, 'handle']);
});
```

### Idempotency

`Idempotency` middleware caches successful non-safe responses (`POST`, `PUT`, `PATCH`, `DELETE`) by `X-Request-Id`.

Rules:
- Request id must be UUID v4, otherwise `400`
- Same id while first request is in progress returns `409`
- Only successful (`2xx`) responses are cached
- Cached response contains `X-Idempotency-Cache-Hit: true`
- Cached headers exclude `date` and `set-cookie`

### Client-side usage

Always generate and reuse the same request id on retries:

```php
use Illuminate\Support\Str;

$requestId = Str::uuid()->toString();

$response = $client->service('oms')
    ->post('/api/orders', ['product_id' => 1])
    ->withHeaders(['X-Request-Id' => $requestId])
    ->send();
```

### Automatic and manual usage

`Gateway::routes()` adds idempotency middleware automatically to proxied routes.

For backend routes, add it manually:

```php
use Jurager\Microservice\Http\Middleware\Idempotency;
use Jurager\Microservice\Http\Middleware\TrustService;

Route::middleware([TrustService::class, Idempotency::class])->group(function () {
    Route::post('/api/orders', [OrderController::class, 'store']);
});
```

## Gateway and Discovery

### How it works

1. Service runs `microservice:register`.
2. Manifest is stored in Redis (or pushed to gateway endpoint).
3. Gateway calls `Gateway::routes()` to materialize routes.
4. Requests are proxied via `ProxyController` to target service.

### Microservice setup

```php
// config/microservice.php
'services' => [
    'gateway' => [
        'base_urls' => ['http://gateway:8000'],
    ],
],

'manifest' => [
    'gateway' => 'gateway',
    'prefix' => 'api',
],
```

Register manifest on deploy and schedule refresh:

```bash
php artisan microservice:register
```

```php
// bootstrap/app.php
->withSchedule(function (Schedule $schedule) {
    $schedule->command('microservice:register')->everyFiveMinutes();
})
```

### Gateway setup

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

### Route prefixes

By default, each proxied route is prefixed by service name.

```php
use Jurager\Microservice\Gateway\Gateway;
use Jurager\Microservice\Gateway\GatewayRoutes;

Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('pim')->prefix('catalog');
    $routes->service('oms')->prefix('orders');
});
```

### Override specific proxied routes

```php
Gateway::routes(function (GatewayRoutes $routes) {
    $routes->service('oms')
        ->post('/api/orders')
        ->middleware(['audit']);

    $routes->service('pim')
        ->get('/api/products/{product}', [ProductController::class, 'show']);
});
```

### Route metadata

Attach metadata on service routes:

```php
$route = Route::get('/products', [ProductController::class, 'index'])->name('products.index');

$route->setAction(array_merge($route->getAction(), [
    'permissions' => ['products.view'],
    'rate_limit' => 60,
]));
```

Read it on gateway route:

```php
$request->route()->getAction('permissions');
$request->route()->getAction('rate_limit');
$request->route()->getAction('_service');
```

### Manifest receiver

Gateway automatically exposes `POST /microservice/manifest` with `TrustService` middleware.

- if `manifest.gateway` is set: service pushes manifest to gateway
- if `manifest.gateway` is `null`: service stores manifest in local Redis

### Proxy behavior

Default `ProxyController`:
- Forwards method, path, query and JSON body
- Signs outgoing request
- Forwards `X-Forwarded-*` headers
- Preserves service status and headers
- Strips `Transfer-Encoding` and `Connection`

Override proxy controller for all routes:

```php
Gateway::routes(controller: App\Http\Controllers\MyProxyController::class);
```

### TrustProxies integration

When `manifest.gateway` is configured, package auto-configures trusted proxies so URL generation on backend uses gateway origin (`host/proto/prefix`) instead of internal service URL.

### Route cache

For production, rebuild route cache after manifest updates:

```bash
php artisan route:cache
```

When gateway receives a new manifest, current route cache is cleared automatically.

### Manual route resolution

```php
use Jurager\Microservice\Registry\RouteRegistry;

$registry = app(RouteRegistry::class);

$match = $registry->resolve('GET', '/api/products/123');
$routes = $registry->getAllRoutes();
$manifests = $registry->getAllManifests();
```

## Operations

### Health Tracking

`HealthRegistry` stores failure counters per service instance in Redis.

- Instance becomes unhealthy after `failure_threshold`
- After `recovery_timeout`, instance is retried
- Successful request resets instance health state

Command:

```bash
php artisan microservice:health
```

### Events

| Event | Trigger |
|---|---|
| `ServiceRequestFailed` | each failed attempt before failover |
| `ServiceBecameUnavailable` | all instances exhausted |
| `ServiceHealthChanged` | health status changed |
| `HealthCheckFailed` | failure counter increased |
| `RoutesRegistered` | service manifest registered |
| `ManifestReceived` | gateway accepted manifest |
| `IdempotentRequestDetected` | response served from idempotency cache |

Register listeners in `EventServiceProvider` or use attribute discovery.

### Artisan Commands

| Command | Description |
|---|---|
| `microservice:register` | build + register route manifest |
| `microservice:health` | show current health state of instances |

### Redis Keys

All keys are prefixed with `microservice.redis.prefix` (default `microservice:`).

| Key Pattern | Purpose | TTL |
|---|---|---|
| `{prefix}health:{service}:{md5(url)}` | instance failure state | `recovery_timeout * 2` |
| `{prefix}manifest:{service}` | route manifest JSON | `manifest.ttl` |
| `{prefix}idempotency:{request_id}` | cached response | `idempotency.ttl` |
| `{prefix}idempotency:{request_id}:lock` | in-flight lock | `idempotency.lock_timeout` |

## Testing

```bash
composer test
```

The package uses [Orchestra Testbench](https://github.com/orchestral/testbench). Redis interaction is mocked in tests, so local Redis is not required for test execution.

## License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).
