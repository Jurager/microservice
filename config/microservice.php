<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service Name
    |--------------------------------------------------------------------------
    |
    | Unique identifier for this microservice. Used in HMAC signing
    | and manifest registration.
    |
    */

    'name' => env('SERVICE_NAME', 'app'),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, TrustGateway middleware skips HMAC signature verification
    | and SERVICE_SECRET validation is bypassed.
    | FOR LOCAL DEVELOPMENT ONLY. Must be disabled in production.
    |
    */

    'debug' => env('SERVICE_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Service Secret
    |--------------------------------------------------------------------------
    |
    | Shared HMAC secret for inter-service request signing.
    | All services in the cluster must use the same value.
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
    | Maximum allowed age (in seconds) of an incoming signed request.
    | Requests older than this value are rejected.
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
    | Trust All Proxies
    |--------------------------------------------------------------------------
    |
    | When true (default), the service provider calls TrustProxies::at('*')
    | when no manifest.services are configured. Set to false to manage
    | trusted proxies yourself.
    |
    */

    'trust_all_proxies' => true,

    /*
    |--------------------------------------------------------------------------
    | Service Discovery
    |--------------------------------------------------------------------------
    |
    | When 'pattern' is set, service URLs are resolved by substituting
    | {service} in the pattern (e.g. Kubernetes DNS).
    | When null, URLs are read from manifests stored in Redis.
    |
    | Example: 'http://{service}.default.svc.cluster.local'
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
    | Configuration published by this service into Redis so the gateway
    | and other services can discover it.
    |
    | timeout       — HTTP timeout (seconds) callers should use for this service.
    | ttl           — How long the manifest lives in Redis (seconds).
    | prefix        — Only routes matching this URI prefix are published.
    | services      — Gateway-only: comma-separated list of services to sync.
    | sync_interval — Gateway-only: how often to run microservice:sync (minutes). 0 to disable.
    |
    */

    'manifest' => [
        'timeout' => env('SERVICE_TIMEOUT', 30),
        'ttl' => env('SERVICE_MANIFEST_TTL', 300),
        'prefix' => env('SERVICE_MANIFEST_PREFIX', 'api'),
        'services' => env('SERVICE_MANIFEST_SERVICES', ''),
        'sync_interval' => env('SERVICE_MANIFEST_SYNC_INTERVAL', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Endpoint
    |--------------------------------------------------------------------------
    |
    | Gateway-only. Exposes a health endpoint showing sync status for all configured services.
    |
    */

    'health' => [
        'endpoint' => env('SERVICE_HEALTH_ENDPOINT', '/microservice/health'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | ttl          — how long responses are cached by X-Request-Id (seconds).
    | lock_timeout — max time a request can hold the processing lock (seconds).
    |
    */

    'idempotency' => [
        'ttl' => env('SERVICE_IDEMPOTENCY_TTL', 86400),
        'lock_timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Automatic retry on connection failures and 5xx server errors.
    |
    | max        — maximum number of retries (0 = disabled).
    | delay      — base delay in milliseconds before the first retry.
    | multiplier — exponential backoff multiplier (2.0 = 100ms, 200ms, 400ms...).
    |
    */

    'retries' => [
        'max' => env('SERVICE_RETRIES_MAX', 0),
        'delay' => env('SERVICE_RETRIES_DELAY', 100),
        'multiplier' => env('SERVICE_RETRIES_MULTIPLIER', 2.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Protects services from cascading failures. After `threshold` consecutive
    | failures within `window` seconds, the circuit opens for `timeout` seconds.
    | Set threshold to 0 to disable.
    |
    */

    'circuit_breaker' => [
        'threshold' => env('SERVICE_CIRCUIT_BREAKER_THRESHOLD', 0),
        'timeout' => env('SERVICE_CIRCUIT_BREAKER_TIMEOUT', 30),
        'window' => env('SERVICE_CIRCUIT_BREAKER_WINDOW', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Distributed Tracing
    |--------------------------------------------------------------------------
    |
    | W3C Trace Context propagation. When enabled, outgoing requests include
    | traceparent and tracestate headers.
    |
    */

    'tracing' => [
        'enabled' => env('SERVICE_TRACING_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proxy Settings
    |--------------------------------------------------------------------------
    |
    | Headers stripped from proxied responses to avoid conflicts
    | with gateway-level headers (e.g. CORS set by nginx).
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

];
