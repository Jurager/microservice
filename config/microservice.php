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
    | lock_timeout: Maximum time (in seconds) a request can hold the processing lock.
    |
    */

    'idempotency' => [
        'ttl' => 60,
        'lock_timeout' => 10,
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
    ],
];
