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

- `name` is the service identifier used in signatures and manifests.
- `debug` disables signature verification in `TrustGateway` (local only).
- `secret` is shared across all services.
- `timestamp_tolerance` controls max request age in seconds.

> [!WARNING]
> Never enable `debug` in production.

## Services Registry

```php
'services' => [
    'oms' => [
        'base_urls' => ['http://oms-1:8000', 'http://oms-2:8000'],
        'timeout' => 5,
        'retries' => 2,
    ],
],
```

- `base_urls` is required for any service you want to call.
- `timeout` and `retries` override defaults per service.

## Defaults

```php
'defaults' => [
    'timeout' => 5,
    'retries' => 2,
    'retry_delay' => 100, // ms
],
```

## Redis

```php
'redis' => [
    'connection' => env('SERVICE_REDIS_CONNECTION', 'default'),
    'prefix' => 'microservice:',
],
```

Redis is used for health state, manifests, and idempotency.

## Health

```php
'health' => [
    'endpoint' => env('SERVICE_HEALTH_ENDPOINT'),
    'failure_threshold' => 3,
    'recovery_timeout' => 30,
],
```

- `endpoint` adds a health route if set.
- `failure_threshold` marks an instance unhealthy.
- `recovery_timeout` controls when to retry an unhealthy instance.

## Manifest (Discovery)

```php
'manifest' => [
    'ttl' => 300,
    'prefix' => 'api',
    'gateway' => env('MANIFEST_GATEWAY_SERVICE'),
],
```

- Only routes with the given prefix are included.
- If `gateway` is set, manifests are pushed to that service.
- If `gateway` is `null`, manifests are stored in Redis.

> [!NOTE]
> When `manifest.gateway` is set, the package trusts all proxies for correct URL generation.

> [!NOTE]
> `HEAD` routes are excluded from the manifest.

## Idempotency

```php
'idempotency' => [
    'ttl' => 86400,
    'lock_timeout' => 10,
],
```

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

These headers are removed from proxied responses to avoid conflicts.
