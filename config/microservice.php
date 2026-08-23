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
    | Service Version
    |--------------------------------------------------------------------------
    |
    | Optional build/release identifier surfaced in the health report's
    | instance block. Useful for spotting a misbehaving replica.
    |
    */
    'version' => env('SERVICE_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Disables signature verification and signing config validation.
    | Intended for local development only.
    | Never enable in production environments.
    |
    */
    'debug' => env('SERVICE_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Signing
    |--------------------------------------------------------------------------
    |
    | Each service signs its own outgoing requests and events with its own
    | ECDSA (P-256) private key. Compromising one service's key lets an
    | attacker forge traffic as that service only — it never exposes any
    | other service's signing capability.
    |
    | A service's public key travels as part of its own manifest — the same
    | public endpoint already used for route discovery — so a verifier
    | resolves it from there rather than holding a static copy.
    |
    | private_key  This service's own PEM private key, base64-wrapped for
    |              env storage. Never shared.
    |
    | Generate it with:
    |   php artisan microservice:keygen
    |
    */
    'signing' => [
        'private_key' => env('SERVICE_PRIVATE_KEY', ''),
    ],

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
    | ttl           Manifest lifetime in Redis (seconds). 0 keeps it until it is
    |               replaced by the next sync. Set this well above sync_interval:
    |               an expired manifest makes the service unroutable, so the value
    |               only decides how long a manifest outlives a stopped sync.
    | prefix        Only routes matching this URI prefix are exposed.
    | services      Gateway-only list of services to synchronize.
    | sync_interval Gateway-only sync interval in minutes (0 disables syncing).
    |
    */
    'manifest' => [
        'timeout' => env('SERVICE_TIMEOUT', 30),
        'ttl' => env('SERVICE_MANIFEST_TTL', 0),
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
        /*
        | Detailed health report (human/dashboard oriented).
        | Returns 200 when healthy/degraded, 503 when unhealthy.
        | Set to null to disable.
        */
        'endpoint' => env('SERVICE_HEALTH_ENDPOINT', '/microservice/health'),

        /*
        | Liveness probe — process is up. No external dependencies are
        | touched, so it answers instantly. Suitable for k8s livenessProbe.
        | Set to null to disable.
        */
        'liveness_endpoint' => env('SERVICE_HEALTH_LIVENESS_ENDPOINT', '/microservice/health/live'),

        /*
        | Readiness probe — gateway is able to serve traffic (Redis reachable
        | and manifests present). Returns 503 when not ready so the
        | orchestrator removes the instance from rotation.
        | Set to null to disable.
        */
        'readiness_endpoint' => env('SERVICE_HEALTH_READINESS_ENDPOINT', '/microservice/health/ready'),

        /*
        | Prometheus metrics endpoint (text/plain exposition format).
        | Set to null to disable.
        */
        'metrics_endpoint' => env('SERVICE_HEALTH_METRICS_ENDPOINT', '/microservice/metrics'),

        /*
        | Expose infrastructure config (base_url, timeout) in the detailed
        | report. Disabled by default to avoid leaking topology; can still be
        | requested per-call with ?verbose=1 when this is true.
        */
        'verbose' => env('SERVICE_HEALTH_VERBOSE', false),

        /*
        | Seconds to cache the detailed report (and /metrics). The heavy
        | checks (RabbitMQ, DLQ) open a broker connection, so without caching
        | every scrape reconnects — and stalls on the connect timeout while
        | the broker is down. Keep this at or above your Prometheus scrape
        | interval so periodic scrapes reuse one probe. 0 disables caching.
        | The liveness and readiness probes are never cached.
        */
        'cache_ttl' => env('SERVICE_HEALTH_CACHE_TTL', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Prevents duplicate request processing using X-Request-Id.
    |
    | ttl           Cached response lifetime in seconds.
    | lock_timeout  Maximum processing lock duration in seconds.
    | max_body_size Largest response body (bytes) worth caching for replay.
    |               Bigger responses are still returned, just not cached.
    |               0 removes the limit.
    |
    */
    'idempotency' => [
        'ttl' => env('SERVICE_IDEMPOTENCY_TTL', 86400),
        'lock_timeout' => 10,
        'max_body_size' => env('SERVICE_IDEMPOTENCY_MAX_BODY_SIZE', 1048576),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum seconds to wait while establishing a TCP connection.
    | Keeps this short so a dead service fails fast instead of
    | holding a PHP-FPM worker for the full request timeout.
    |
    */
    'connect_timeout' => env('SERVICE_CONNECT_TIMEOUT', 5),

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
    | enabled          Disables publishing and listeners when false.
    | exchange         Topic exchange used for event routing.
    | confirm_timeout  Seconds to wait for the broker to confirm a publish.
    | publish_attempts Publish attempts, counting the retry on a reopened connection.
    | max_idle         Seconds a publisher connection may sit unused before it is
    |                  reopened: an idle TCP session is dropped by NAT or the broker
    |                  silently, and the client only finds out on the next write.
    | connection       RabbitMQ connection settings.
    |
    | Events are published as mandatory: one that matches no queue is returned
    | by the broker and fails the publish, instead of being discarded silently
    | while no listener has ever declared its queue.
    |
    */
    'bus' => [
        'enabled' => env('MESSAGE_BUS_ENABLED', true),
        'exchange' => env('MESSAGE_BUS_EXCHANGE', 'events'),
        'confirm_timeout' => (int) env('MESSAGE_BUS_CONFIRM_TIMEOUT', 5),
        'publish_attempts' => (int) env('MESSAGE_BUS_PUBLISH_ATTEMPTS', 2),
        'max_idle' => (int) env('MESSAGE_BUS_MAX_IDLE', 60),

        'connection' => [
            'host' => env('RABBITMQ_HOST', '127.0.0.1'),
            'port' => (int) env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
            'heartbeat' => (int) env('RABBITMQ_HEARTBEAT', 60),
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
            'enabled' => env('MESSAGE_BUS_DLQ_ENABLED', true),
            'exchange' => env('MESSAGE_BUS_DLQ_EXCHANGE', 'events.dlx'),
        ],
    ],

];
