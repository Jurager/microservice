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
    | Redis Configuration
    |--------------------------------------------------------------------------
    */

    'redis' => [
        'connection' => env('SERVICE_REDIS_CONNECTION', 'default'),
        'prefix' => 'microservice:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Discovery
    |--------------------------------------------------------------------------
    |
    | When 'pattern' is set, service base URLs are resolved by substituting
    | {service} in the pattern. Suitable for environments with predictable
    | DNS naming (e.g. Kubernetes).
    |
    | Example for Kubernetes:
    |   'pattern' => 'http://{service}.default.svc.cluster.local'
    |
    | When null, base URLs are resolved from the service manifest stored
    | in the gateway's local Redis (populated via microservice:sync).
    |
    */

    'discovery' => [
        'pattern' => env('SERVICE_DISCOVERY_PATTERN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Manifest Registration
    |--------------------------------------------------------------------------
    |
    | Configuration published by this service into the shared Redis so that
    | the gateway and other services can discover it.
    |
    | base_urls  — reachable addresses of this service instance.
    | timeout    — default HTTP timeout (seconds) for callers of this service.
    | ttl        — how long the manifest lives in Redis before expiring (seconds).
    | prefix     — only routes matching this URI prefix are included.
    |
    */

    'manifest' => [
        /*
        | Settings published by this service so gateways can discover it.
        */
        'base_urls' => [env('APP_URL', 'http://localhost')],
        'timeout'   => env('SERVICE_TIMEOUT', 5),
        'ttl'       => 300,
        'prefix'    => env('SERVICE_MANIFEST_PREFIX', 'api'),

        /*
        | Gateway-only: list of service names to pull manifests from.
        | Used by the microservice:sync command and the health endpoint.
        |
        | Example: ['oms', 'pim', 'agm']
        */
        'services'  => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Endpoint
    |--------------------------------------------------------------------------
    |
    | Gateway-only. When set, exposes a health endpoint at the given URI
    | showing sync status for all configured services.
    |
    | Example: '/microservice/health'
    |
    */

    'health' => [
        'endpoint' => env('SERVICE_HEALTH_ENDPOINT'),
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
    ],

];
