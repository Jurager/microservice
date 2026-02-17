<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service Name
    |--------------------------------------------------------------------------
    |
    | Unique identifier for the current microservice instance.
    | Used in HMAC signing and manifest registration.
    |
    */

    'name' => env('SERVICE_NAME', 'app'),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the TrustGateway middleware will skip HMAC signature
    | verification, allowing direct requests to the service.
    | Must be disabled in production.
    |
    */

    'debug' => env('SERVICE_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Service Secret
    |--------------------------------------------------------------------------
    |
    | Shared secret for HMAC request signing between services.
    | All services in the cluster must use the same secret.
    | Generate with: openssl rand -base64 32
    |
    */

    'secret' => env('SERVICE_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | HMAC Algorithm
    |--------------------------------------------------------------------------
    */

    'algorithm' => 'sha256',

    /*
    |--------------------------------------------------------------------------
    | Signature Timestamp Tolerance
    |--------------------------------------------------------------------------
    |
    | Maximum allowed age (in seconds) for incoming signed requests.
    | Requests with a timestamp older than this value will be rejected.
    |
    */

    'timestamp_tolerance' => 60,

    /*
    |--------------------------------------------------------------------------
    | Services Registry
    |--------------------------------------------------------------------------
    |
    | Known services and their instances. Each service can have multiple
    | base URLs for failover. Per-service timeout and retries override defaults.
    |
    | Example:
    |   'oms' => [
    |       'base_urls' => ['http://oms-1:8000', 'http://oms-2:8000'],
    |       'timeout'   => 5,
    |       'retries'   => 2,
    |   ],
    |
    */

    'services' => [],

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    */

    'redis' => [
        'connection' => env('SERVICE_REDIS_CONNECTION', 'default'),
        'prefix' => 'microservice:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Tracking
    |--------------------------------------------------------------------------
    |
    | Controls when a service instance is marked as unhealthy
    | and how long before it's retried.
    |
    */

    'health' => [
        'endpoint' => env('SERVICE_HEALTH_ENDPOINT'),
        'failure_threshold' => 3,
        'recovery_timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Manifest Registration
    |--------------------------------------------------------------------------
    |
    | When enabled, the service can register its route manifest
    | for auto-discovery by the gateway.
    |
    | If 'gateway' is set, the manifest is pushed via HTTP to the
    | gateway service (must be registered in 'services' above).
    | If null, the manifest is stored in local Redis.
    |
    */

    'manifest' => [
        'ttl' => 300,
        'prefix' => 'api',
        'gateway' => env('MANIFEST_GATEWAY_SERVICE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency Middleware
    |--------------------------------------------------------------------------
    |
    | TTL in seconds for caching responses by X-Request-Id.
    | Standard is 24 hours (86400 seconds) to ensure clients can safely retry
    | failed requests within a day and receive the same response.
    |
    | lock_timeout: Maximum time (in seconds) a request can hold the processing lock.
    |
    */

    'idempotency' => [
        'ttl' => 86400, // 24 hours
        'lock_timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Proxy Settings
    |--------------------------------------------------------------------------
    |
    | Headers listed here will be stripped from proxied microservice
    | responses to prevent conflicts with the gateway's own headers
    | (e.g. CORS or security headers set by nginx).
    |
    */

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

    /*
    |--------------------------------------------------------------------------
    | Default Request Settings
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'timeout' => 5,
        'retries' => 2,
        'retry_delay' => 100, // ms

        /*
        |--------------------------------------------------------------------------
        | Propagate Original Exception
        |--------------------------------------------------------------------------
        |
        | When enabled, if all retry attempts fail and an underlying exception
        | was captured (e.g. a ConnectException or RequestException), it will be
        | re-thrown as-is instead of being wrapped in ServiceUnavailableException.
        | Useful when you want the original error message to reach the client.
        |
        */

        'propagate_exception' => env('SERVICE_PROPAGATE_EXCEPTION', false),
    ],
];
