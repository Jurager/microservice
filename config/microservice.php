<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service Name
    |--------------------------------------------------------------------------
    |
    | Unique service identifier used for request signing,
    | service discovery, and event metadata.
    |
    */
    'name' => env('SERVICE_NAME', 'app'),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Disables HMAC signature verification and secret validation.
    | Intended for local development only.
    | Never enable in production environments.
    |
    */
    'debug' => env('SERVICE_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Service Secret
    |--------------------------------------------------------------------------
    |
    | Shared secret used for HMAC signing between services.
    | Every service in the cluster must use the same value.
    |
    | Generate a secure key with:
    |   openssl rand -base64 32
    |
    */
    'secret' => env('SERVICE_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | HMAC Algorithm
    |--------------------------------------------------------------------------
    |
    | Hashing algorithm used for request and message signatures.
    |
    */
    'algorithm' => 'sha256',

    /*
    |--------------------------------------------------------------------------
    | Signature Timestamp Tolerance
    |--------------------------------------------------------------------------
    |
    | Maximum allowed age (in seconds) for signed requests.
    | Older requests are rejected to prevent replay attacks.
    |
    */
    'timestamp_tolerance' => 60,

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    |
    | Redis connection and key prefix used by the package.
    |
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
    | Automatically trusts all reverse proxies when no
    | manifest services are configured.
    |
    | Disable this if proxy trust is managed manually.
    |
    */
    'trust_all_proxies' => true,

    /*
    |--------------------------------------------------------------------------
    | Service Discovery
    |--------------------------------------------------------------------------
    |
    | Controls how service URLs are resolved.
    |
    | If a pattern is configured, `{service}` placeholders are
    | replaced dynamically (useful for Kubernetes DNS).
    |
    | If null, service manifests are resolved from Redis.
    |
    | Example:
    |   http://{service}.default.svc.cluster.local
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
    | Metadata published to Redis for service discovery.
    |
    | timeout       HTTP timeout (seconds) clients should use.
    | ttl           Manifest lifetime in Redis (seconds).
    | prefix        Only routes matching this URI prefix are exposed.
    | services      Gateway-only list of services to synchronize.
    | sync_interval Gateway-only sync interval in minutes (0 disables syncing).
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
    | Gateway-only health check endpoint exposing
    | synchronization and service status information.
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
    | Prevents duplicate request processing using X-Request-Id.
    |
    | ttl          Cached response lifetime in seconds.
    | lock_timeout Maximum processing lock duration in seconds.
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
    | Automatic retries for connection failures and 5xx responses.
    |
    | max        Maximum retry attempts (0 disables retries).
    | delay      Initial retry delay in milliseconds.
    | multiplier Exponential backoff multiplier.
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
    | Prevents cascading failures between services.
    |
    | The circuit opens after `threshold` consecutive failures
    | within the configured `window`.
    |
    | Once opened, requests are blocked for `timeout` seconds.
    | Set threshold to 0 to disable the breaker.
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
    | Enables W3C Trace Context propagation.
    |
    | Outgoing requests include:
    |   - traceparent
    |   - tracestate
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
    | Response headers removed from proxied responses
    | to avoid conflicts with gateway or web server headers.
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
    | Message Bus
    |--------------------------------------------------------------------------
    |
    | RabbitMQ-based inter-service event bus.
    |
    | enabled    Disables publishing and listeners when false.
    | exchange   Topic exchange used for event routing.
    | connection RabbitMQ connection settings.
    |
    */
    'bus' => [
        'enabled'  => env('MESSAGE_BUS_ENABLED', true),
        'exchange' => env('MESSAGE_BUS_EXCHANGE', 'events'),

        'connection' => [
            'host'               => env('RABBITMQ_HOST', '127.0.0.1'),
            'port'               => (int) env('RABBITMQ_PORT', 5672),
            'user'               => env('RABBITMQ_USER', 'guest'),
            'password'           => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost'              => env('RABBITMQ_VHOST', '/'),
            'heartbeat'          => (int) env('RABBITMQ_HEARTBEAT', 60),
            'connection_timeout' => (int) env('RABBITMQ_TIMEOUT', 10),
        ],

        /*
        |--------------------------------------------------------------------------
        | Dead Letter Queue (DLQ)
        |--------------------------------------------------------------------------
        |
        | Failed messages are routed to a dead-letter exchange instead
        | of being acknowledged and discarded.
        |
        | This includes:
        |   - invalid signatures
        |   - malformed payloads
        |   - handler exceptions
        |
        | Dead-letter queues are created per handler using:
        |   {service}.{type}.dlq
        |
        | Important:
        | Changing DLQ settings for existing queues may require
        | deleting and recreating the original RabbitMQ queues.
        |
        */
        'dead_letter' => [
            'enabled'  => env('MESSAGE_BUS_DLQ_ENABLED', true),
            'exchange' => env('MESSAGE_BUS_DLQ_EXCHANGE', 'events.dlx'),
        ],
    ],

];
