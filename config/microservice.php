<?php

return [

    /*
     * The unique name of this service, used in request signatures,
     * service discovery, and published events.
     */
    'name' => env('SERVICE_NAME', 'app'),

    /*
     * An optional build or release version to include in health reports.
     */
    'version' => env('SERVICE_VERSION'),

    'signing' => [

        /*
         * Sign outgoing requests and events, and verify incoming signatures.
         * Only disable this for local development: incoming traffic will then
         * be accepted without verification.
         */
        'enabled' => env('SERVICE_SIGNING_ENABLED', true),

        /*
         * This service's base64-encoded PEM private key. Generate it with
         * `php artisan microservice:keygen`. Do not share it with other services.
         */
        'private_key' => env('SERVICE_PRIVATE_KEY', ''),

        /*
         * The base64-encoded certificate issued for this service's name and
         * public key by the cluster's certificate authority.
         */
        'certificate' => env('SERVICE_CERTIFICATE', ''),

        /*
         * The certificate authority's base64-encoded PEM public key. Use the
         * same value on every service. The CA private key is only needed when
         * issuing certificates and must not be deployed to running services.
         */
        'ca_public_key' => env('SERVICE_CA_PUBLIC_KEY', ''),
    ],

    'cache' => [

        /*
         * The cache store used for manifests, idempotency, and circuit breakers.
         * It must be shared by all instances and support atomic locks, such as
         * Redis, Memcached, DynamoDB, or database. Null uses the default store.
         */
        'store' => env('SERVICE_CACHE_STORE'),
    ],

    /*
     * Render exceptions on API routes and requests expecting JSON as
     * JSON:API errors. Set this to false to use your own exception renderer.
     */
    'render_exceptions' => env('SERVICE_RENDER_EXCEPTIONS', true),

    /*
     * The allowed difference, in seconds, between a request's signature
     * timestamp and the receiving service's clock.
     */
    'timestamp_tolerance' => 60,

    'discovery' => [

        /*
         * The URL pattern used to reach services, with {service} replaced by
         * the service name. For example: http://{service}:8000.
         *
         * Leave this null to use URLs from cached manifests. Explicit peer URLs
         * always take precedence.
         */
        'pattern' => env('SERVICE_DISCOVERY_PATTERN'),

        /*
         * The number of seconds to reuse a service URL read from a manifest.
         */
        'cache_ttl' => env('SERVICE_DISCOVERY_CACHE_TTL', 60),
    ],

    /*
     * Services with their own URLs outside the discovery pattern. These
     * are synchronized alongside manifest services, so do not list a name
     * in both places. Entries with an empty URL are ignored.
     *
     * For example: 'catalog' => env('CATALOG_URL').
     */
    'peers' => [],

    'manifest' => [

        /*
         * The HTTP request timeout, in seconds, advertised to other services.
         */
        'timeout' => env('SERVICE_TIMEOUT', 30),

        /*
         * The number of seconds to keep a manifest in the cache. Set this to
         * 0 to keep it until the next synchronization replaces it.
         *
         * A nonzero value should exceed the synchronization interval. An expired
         * manifest makes the service's gateway routes unavailable.
         */
        'ttl' => env('SERVICE_MANIFEST_TTL', 0),

        /*
         * Only publish routes under these URI prefixes. You may use a single
         * prefix, a comma-separated string, or an array. An empty value publishes
         * all routes.
         */
        'prefix' => env('SERVICE_MANIFEST_PREFIX', 'api'),

        /*
         * The services whose manifests this gateway should synchronize.
         * Use a comma-separated list of service names.
         */
        'services' => env('SERVICE_MANIFEST_SERVICES', ''),

        /*
         * The manifest synchronization interval, in minutes. Set this to 0 to
         * disable scheduled synchronization and run microservice:sync yourself.
         */
        'sync_interval' => env('SERVICE_MANIFEST_SYNC_INTERVAL', 5),
    ],

    'health' => [

        /*
         * The detailed health report URL. Returns 503 when unhealthy and 200
         * when healthy or degraded. Set this to null to disable the endpoint.
         */
        'endpoint' => env('SERVICE_HEALTH_ENDPOINT', '/microservice/health'),

        /*
         * The liveness probe URL. Checks that the process is running without
         * checking external dependencies. Set this to null to disable it.
         */
        'liveness_endpoint' => env('SERVICE_HEALTH_LIVENESS_ENDPOINT', '/microservice/health/live'),

        /*
         * The readiness probe URL. Returns 503 when critical dependencies or
         * service manifests are unavailable. Set this to null to disable it.
         */
        'readiness_endpoint' => env('SERVICE_HEALTH_READINESS_ENDPOINT', '/microservice/health/ready'),

        /*
         * The URL exposing the health report as Prometheus metrics.
         * Set this to null to disable the endpoint.
         */
        'metrics_endpoint' => env('SERVICE_HEALTH_METRICS_ENDPOINT', '/microservice/metrics'),

        /*
         * Include service URLs, timeouts, and dependency errors in health
         * reports. When enabled, callers may hide them with ?verbose=0.
         */
        'verbose' => env('SERVICE_HEALTH_VERBOSE', false),

        /*
         * The number of seconds to cache detailed health reports and metrics.
         * Set this to 0 to disable caching. Liveness and readiness probes are
         * never cached.
         */
        'cache_ttl' => env('SERVICE_HEALTH_CACHE_TTL', 15),

        /*
         * The dependency checks used by health reports and readiness probes.
         * You may add classes implementing Health\Contracts\DependencyCheck.
         * Only critical checks affect readiness.
         */
        'checks' => [
            \Jurager\Microservice\Health\Checks\CacheCheck::class,
            \Jurager\Microservice\Health\Checks\BrokerCheck::class,
        ],
    ],

    'idempotency' => [

        /*
         * The number of seconds to keep a response for requests repeated with
         * the same X-Request-Id, HTTP method, and path.
         */
        'ttl' => env('SERVICE_IDEMPOTENCY_TTL', 86400),

        /*
         * The processing lock duration, in seconds. Choose a value long enough
         * to cover the expected request duration.
         */
        'lock_timeout' => 10,

        /*
         * The largest response body to cache, in bytes. Larger responses and
         * streamed responses are not cached, so repeated requests run again.
         * Set this to 0 to remove the size limit.
         */
        'max_body_size' => env('SERVICE_IDEMPOTENCY_MAX_BODY_SIZE', 1048576),
    ],

    /*
     * The maximum number of seconds to wait when opening an HTTP connection.
     */
    'connect_timeout' => env('SERVICE_CONNECT_TIMEOUT', 5),

    'retries' => [

        /*
         * The number of retries after the initial HTTP request. Set this to 0
         * to disable retries.
         *
         * Safe requests may retry connection failures and temporary server errors.
         * Writes retry only before a connection is established, unless the request
         * is marked idempotent and the receiving service deduplicates it.
         */
        'max' => env('SERVICE_RETRIES_MAX', 0),

        /*
         * The delay before the first retry, in milliseconds.
         */
        'delay' => env('SERVICE_RETRIES_DELAY', 100),

        /*
         * The factor applied to the delay for each subsequent retry. For example,
         * 2 doubles the delay on every retry.
         */
        'multiplier' => env('SERVICE_RETRIES_MULTIPLIER', 2.0),
    ],

    'circuit_breaker' => [

        /*
         * The consecutive failures before requests to a service are blocked.
         * Set this to 0 to disable the circuit breaker.
         */
        'threshold' => env('SERVICE_CIRCUIT_BREAKER_THRESHOLD', 0),

        /*
         * The cooldown, in seconds, before one probe request is allowed.
         * A successful probe resumes normal requests.
         */
        'timeout' => env('SERVICE_CIRCUIT_BREAKER_TIMEOUT', 30),

        /*
         * The number of seconds a failure streak is remembered.
         */
        'window' => env('SERVICE_CIRCUIT_BREAKER_WINDOW', 60),
    ],

    'tracing' => [

        /*
         * Propagate W3C traceparent and tracestate headers on outgoing requests.
         */
        'enabled' => env('SERVICE_TRACING_ENABLED', true),

        /*
         * Include request durations in the Server-Timing response header.
         * Register the LogContext middleware to enable this header.
         */
        'timings' => env('SERVICE_TIMINGS_ENABLED', true),
    ],

    'proxy' => [

        /*
         * The client request headers to forward to downstream services.
         * X-Request-Id is always forwarded. Add Authorization here if downstream
         * services authenticate the end user themselves.
         */
        'forward_headers' => [
            'Accept-Language',
        ],

        /*
         * The headers to remove from downstream responses. CORS response headers
         * are removed by default. Connection-related headers are always removed.
         */
        'strip_headers' => [
            'Access-Control-Allow-Origin',
            'Access-Control-Allow-Methods',
            'Access-Control-Allow-Headers',
            'Access-Control-Allow-Credentials',
            'Access-Control-Expose-Headers',
            'Access-Control-Max-Age',
        ],
    ],

    'bus' => [

        /*
         * Enable RabbitMQ publishing and listeners. When false, publish() does
         * nothing and microservice:listen cannot start.
         */
        'enabled' => env('MESSAGE_BUS_ENABLED', true),

        /*
         * The RabbitMQ topic exchange used to publish events. Publishing fails
         * when no queue is subscribed to the event type.
         */
        'exchange' => env('MESSAGE_BUS_EXCHANGE', 'events'),

        /*
         * The maximum number of seconds to wait for a broker publish confirmation.
         */
        'confirm_timeout' => (int) env('MESSAGE_BUS_CONFIRM_TIMEOUT', 5),

        /*
         * The maximum number of publish attempts, including the first attempt.
         * Connection failures may be retried; unroutable events are not retried.
         */
        'publish_attempts' => (int) env('MESSAGE_BUS_PUBLISH_ATTEMPTS', 2),

        /*
         * The number of seconds a publishing connection may remain idle before
         * it is reopened for the next event.
         */
        'max_idle' => (int) env('MESSAGE_BUS_MAX_IDLE', 60),

        /*
         * Wait for the database transaction to commit before publishing events.
         * Rolled-back transactions publish nothing. Set this to false to publish
         * immediately, including inside transactions.
         */
        'after_commit' => env('MESSAGE_BUS_AFTER_COMMIT', true),

        /*
         * The RabbitMQ connection used by publishers and listeners.
         */
        'connection' => [
            'host' => env('RABBITMQ_HOST', '127.0.0.1'),
            'port' => (int) env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),

            /*
             * The AMQP heartbeat interval, in seconds.
             */
            'heartbeat' => (int) env('RABBITMQ_HEARTBEAT', 60),

            /*
             * The maximum number of seconds to wait when connecting to RabbitMQ.
             */
            'connection_timeout' => (int) env('RABBITMQ_TIMEOUT', 10),

            /*
             * Use TLS for the RabbitMQ connection. Configure certificates below
             * if your broker requires a custom CA or client certificate.
             */
            'secure' => env('RABBITMQ_SECURE', false),

            /*
             * The TLS certificate paths and verification settings. Leave certificate
             * paths null to use the system trust store without a client certificate.
             */
            'ssl' => [
                'cafile' => env('RABBITMQ_SSL_CAFILE'),
                'local_cert' => env('RABBITMQ_SSL_LOCALCERT'),
                'local_pk' => env('RABBITMQ_SSL_LOCALKEY'),
                'passphrase' => env('RABBITMQ_SSL_PASSPHRASE'),
                'verify_peer' => env('RABBITMQ_SSL_VERIFY_PEER', true),
                'verify_peer_name' => env('RABBITMQ_SSL_VERIFY_PEER_NAME', true),
            ],
        ],

        'dead_letter' => [

            /*
             * Send rejected messages to a dead-letter queue named {service}.{type}.dlq.
             * When disabled, rejected messages are discarded. Changing this setting
             * for existing queues may require recreating the affected queues.
             */
            'enabled' => env('MESSAGE_BUS_DLQ_ENABLED', true),

            /*
             * The RabbitMQ exchange used to route rejected messages to dead-letter queues.
             */
            'exchange' => env('MESSAGE_BUS_DLQ_EXCHANGE', 'events.dlx'),
        ],

        /*
         * MessageHandler classes to load in addition to discovered handlers.
         */
        'handlers' => [],

        /*
         * Discover MessageHandler classes in the application directory when true.
         * You may specify an array of directories instead, or false to use only
         * the handlers listed above.
         */
        'discover' => env('MESSAGE_BUS_DISCOVER', true),
    ],

];
