---
title: Installation and setup
weight: 10
---

You can install the package using Composer:

```bash
composer require jurager/microservice
```

## Publishing the configuration

```bash
php artisan vendor:publish --tag=microservice-config
```

The available options are explained in [config/microservice.php](https://github.com/Jurager/microservice/blob/main/config/microservice.php). You can configure most settings through environment variables.

### Cache requirements

Choose a cache store shared by all service instances with support for atomic locks. Redis, Memcached, DynamoDB, and database stores are supported:

```env
SERVICE_CACHE_STORE=redis
```

Leave this unset to use Laravel's default store. Local stores such as `file` are not supported; `array` is allowed in tests.

## Environment configuration

### Required variables

Give every service a unique, stable name and its own signing credentials:

```env
SERVICE_NAME=catalog
SERVICE_PRIVATE_KEY=base64-private-key
SERVICE_CERTIFICATE=base64-certificate
SERVICE_CA_PUBLIC_KEY=base64-ca-public-key
```

Follow [setting up signing credentials](certificates.md) to generate these values. The CA public key is the same on every service.

Signing is enabled by default. Only use `SERVICE_SIGNING_ENABLED=false` for local development; it accepts incoming requests and events without verification.

### Middleware

To add request IDs, trace IDs, and timings, register `LogContext` in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Middleware;
use Jurager\Microservice\Http\Middleware\LogContext;

->withMiddleware(function (Middleware $middleware) {
    $middleware->api(prepend: [LogContext::class]);
})
```

Behind a proxy, configure Laravel's `trustProxies()` with your proxy addresses. Protect service routes with [TrustPeer](security.md#trust-peer).

### Gateway configuration

A gateway also needs the service names and their URL pattern:

```env
SERVICE_MANIFEST_SERVICES=catalog,orders
SERVICE_DISCOVERY_PATTERN=http://{service}:8000
```

Continue with [registering gateway routes](gateway.md#registering-routes).

## Checking your setup

```bash
php artisan about
```

The Microservice section reports signing, cache, and message bus configuration.

## Configuration reference

Settings and defaults live in the [configuration file](https://github.com/Jurager/microservice/blob/main/config/microservice.php). These guides show how to use them:

- [Signing credentials](certificates.md)
- [Retries and circuit breaker](resilience.md)
- [Gateway discovery and manifests](gateway.md)
- [Idempotency](security.md#idempotency)
- [Health endpoints](health.md)
- [Message bus](message-bus.md#configuration)
