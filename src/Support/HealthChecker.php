<?php

declare(strict_types=1);

namespace Jurager\Microservice\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Jurager\Microservice\Bus\HandlerDiscovery;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

/**
 * Builds the gateway health report.
 *
 * Aggregates the concerns that used to be conflated under a single "stale" flag:
 *   - freshness of cached service manifests (route discovery),
 *   - per-service circuit breaker state (is the gateway still calling them?),
 *   - reachability of the gateway's own infrastructure (Redis, RabbitMQ),
 *   - dead-letter backlog (are event handlers failing?),
 *   - an overall, machine-readable status for orchestrators/monitoring.
 */
class HealthChecker
{
    public function __construct(
        private readonly Repository $cache,
    ) {
    }

    public const string STATUS_HEALTHY = 'healthy';

    public const string STATUS_DEGRADED = 'degraded';

    public const string STATUS_UNHEALTHY = 'unhealthy';

    /**
     * Full, human/dashboard oriented report.
     *
     * Heavy checks (RabbitMQ, DLQ) are optionally cached for a few seconds so
     * frequent scrapes of /health and /metrics don't hammer the infrastructure.
     *
     * @param  string|null  $only  Limit the report to a single service.
     * @param  bool|null  $verbose  Expose infrastructure config (base_url, timeout).
     */
    public function report(?string $only = null, ?bool $verbose = null): array
    {
        $verbose ??= (bool) config('microservice.health.verbose', false);
        $ttl = (int) config('microservice.health.cache_ttl', 0);

        $build = fn (): array => $this->buildReport($only, $verbose);

        if ($ttl <= 0) {
            return $build();
        }

        return $this->cache->remember(
            'microservice:health:'.($only ?? 'all').':'.($verbose ? 'v' : 'n'),
            $ttl,
            $build,
        );
    }

    private function buildReport(?string $only, bool $verbose): array
    {
        $services = $this->checkServices($only, $verbose);
        $dependencies = $this->checkDependencies();

        // Status is derived before presentation cleanup, since it depends on
        // the internal `critical` flag that callers never see.
        $status = $this->overallStatus($services, $dependencies);

        return $this->withoutNulls([
            'status' => $status,
            'gateway' => config('microservice.name'),
            'instance' => $this->instance(),
            'checked_at' => Carbon::now()->toIso8601String(),
            'summary' => $this->summarize($services),
            'dependencies' => $this->presentDependencies($dependencies),
            'services' => $services,
        ]);
    }

    /**
     * Strip presentation-only noise from the dependency block: the internal
     * `critical` flag (an implementation detail of status calculation) and the
     * null `latency_ms` left behind by a failed check.
     *
     * @param  array<string, array<string, mixed>>  $dependencies
     * @return array<string, array<string, mixed>>
     */
    private function presentDependencies(array $dependencies): array
    {
        foreach ($dependencies as $name => $dependency) {
            unset($dependency['critical']);
            $dependencies[$name] = $dependency;
        }

        return $dependencies;
    }

    /**
     * Recursively drop null values so the payload carries only meaningful
     * fields (e.g. an unset `version`, or `latency_ms`/`dead_letters` on a
     * failed dependency — the `status` already conveys the failure).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutNulls(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value === null) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->withoutNulls($value);
            }
        }

        return $data;
    }

    /**
     * Readiness: can the gateway serve traffic right now?
     * Redis must be reachable and at least one manifest must be present.
     * Intentionally cheap — no RabbitMQ — so it's safe as a frequent probe.
     */
    public function readiness(): array
    {
        $cacheCheck = $this->checkCache();
        $services = $this->checkServices();

        $loaded = count(array_filter(
            $services,
            static fn (array $s) => $s['status'] !== 'missing',
        ));

        $ready = $cacheCheck['status'] === 'up' && ($services === [] || $loaded > 0);

        return [
            'status' => $ready ? self::STATUS_HEALTHY : self::STATUS_UNHEALTHY,
            'checked_at' => Carbon::now()->toIso8601String(),
            'cache' => $cacheCheck,
            'manifests_loaded' => $loaded,
        ];
    }

    /**
     * Per-service manifest status (+ circuit breaker state when enabled).
     *
     * @return array<string, array<string, mixed>>
     */
    public function checkServices(?string $only = null, bool $verbose = false): array
    {
        $services = $this->services();

        if ($only !== null) {
            $services = in_array($only, $services, true) ? [$only] : [];
        }

        foreach ($services as $service) {
            $raw = $this->safeCache(fn () => $this->cache->get("microservice:manifest:$service"));
            $circuit = $this->circuitState($service);

            if ($raw === null || $raw === false) {
                $result[$service] = array_filter([
                    'status' => 'missing',
                    'routes_count' => 0,
                    'circuit' => $circuit,
                ], static fn ($v) => $v !== null);

                continue;
            }

            $manifest = json_decode((string) $raw, true) ?: [];

            $entry = array_filter([
                'status' => 'healthy',
                'synced_at' => $manifest['synced_at'] ?? null,
                'routes_count' => count($manifest['routes'] ?? []),
                'circuit' => $circuit,
            ], static fn ($v) => $v !== null);

            if ($verbose) {
                $entry['base_url'] = $manifest['base_url'] ?? null;
                $entry['timeout'] = $manifest['timeout'] ?? null;
            }

            $result[$service] = $entry;
        }

        return $result;
    }

    /**
     * Circuit breaker state for a service, mirroring the keys written by
     * ServiceClient. Returns null when the breaker is disabled (threshold 0).
     *
     * @return array<string, mixed>|null
     */
    public function circuitState(string $service): ?array
    {
        $threshold = (int) config('microservice.circuit_breaker.threshold', 0);

        if ($threshold <= 0) {
            return null;
        }

        $key = "microservice:circuit:$service";
        $state = $this->safeCache(fn () => $this->cache->get($key)) ?: 'closed';
        $failures = (int) $this->safeCache(fn () => $this->cache->get($key.':failures'), 0);

        $result = [
            'state' => $state,
            'failures' => $failures,
            'threshold' => $threshold,
        ];

        if ($state === 'open') {
            $openedAt = (int) $this->safeCache(fn () => $this->cache->get($key.':opened'), 0);
            $timeout = (int) config('microservice.circuit_breaker.timeout', 30);

            if ($openedAt > 0) {
                $result['opens_until'] = Carbon::createFromTimestamp($openedAt + $timeout)->toIso8601String();
            }
        }

        return $result;
    }

    /**
     * Gateway infrastructure dependencies.
     *
     * @return array<string, array<string, mixed>>
     */
    public function checkDependencies(): array
    {
        $dependencies = ['cache' => $this->checkCache()];

        if (config('microservice.bus.enabled', true)) {
            $dependencies['rabbitmq'] = $this->checkRabbitMq();
        }

        return $dependencies;
    }

    /**
     * Redis is a critical dependency (discovery, idempotency, breaker state).
     */
    public function checkCache(): array
    {
        $start = microtime(true);

        try {
            $this->cache->has('microservice:ping');

            return [
                'status' => 'up',
                'critical' => true,
                'latency_ms' => $this->elapsedMs($start),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'down',
                'critical' => true,
                'latency_ms' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * RabbitMQ powers the event bus only — its loss degrades, but does not
     * disable, the gateway (HTTP proxying keeps working).
     */
    public function checkRabbitMq(): array
    {
        $start = microtime(true);

        try {
            $connection = $this->busConnection();
            $latency = $this->elapsedMs($start);

            $result = [
                'status' => 'up',
                'critical' => false,
                'latency_ms' => $latency,
                'dead_letters' => $this->deadLetters($connection),
            ];

            $connection->close();

            return $result;
        } catch (Throwable $e) {
            return [
                'status' => 'down',
                'critical' => false,
                'latency_ms' => null,
                'error' => $e->getMessage(),
                'dead_letters' => null,
            ];
        }
    }

    /**
     * Depth of each dead-letter queue for the handlers this gateway consumes,
     * probed over an already-open broker connection.
     *
     * Uses passive queue_declare so no queues are created as a side effect.
     * A failed passive declare (queue absent) closes the channel, so each
     * queue is probed on its own channel off the shared connection.
     *
     * @return array<string, int>
     */
    private function deadLetters(AMQPStreamConnection $connection): array
    {
        if (! config('microservice.bus.dead_letter.enabled', true)) {
            return [];
        }

        $types = $this->handlerTypes();

        if ($types === []) {
            return [];
        }

        $service = (string) config('microservice.name', 'app');
        $result = [];

        foreach ($types as $type) {
            $dlq = "$service.$type.dlq";

            try {
                $channel = $connection->channel();
                $info = $channel->queue_declare($dlq, passive: true);
                $result[$dlq] = is_array($info) ? (int) ($info[1] ?? 0) : 0;
                $channel->close();
            } catch (Throwable) {
                // Queue does not exist yet — nothing dead-lettered. The
                // failed passive declare already closed the channel.
            }
        }

        return $result;
    }

    /**
     * Normalized list of configured services.
     * Accepts an array or a comma-separated string.
     *
     * @return string[]
     */
    public function services(): array
    {
        $raw = config('microservice.manifest.services', []);

        $items = is_array($raw)
            ? $raw
            : array_map('trim', explode(',', (string) $raw));

        return array_values(array_filter($items, static fn ($s) => $s !== ''));
    }

    /**
     * Map an overall status to an HTTP status code.
     */
    public function httpStatus(string $status): int
    {
        return $status === self::STATUS_UNHEALTHY ? 503 : 200;
    }

    /**
     * @return array<string, mixed>
     */
    private function instance(): array
    {
        return [
            'hostname' => gethostname() ?: null,
            'version' => config('microservice.version'),
            'environment' => app()->environment(),
            // Container uptime on Linux; null on platforms without /proc.
            'uptime_seconds' => $this->uptimeSeconds(),
        ];
    }

    private function uptimeSeconds(): ?int
    {
        if (! is_readable('/proc/uptime')) {
            return null;
        }

        $contents = @file_get_contents('/proc/uptime');

        return $contents === false ? null : (int) (float) strtok($contents, ' ');
    }

    /**
     * @return string[]
     */
    private function handlerTypes(): array
    {
        try {
            return array_map(
                static fn (string $handler): string => $handler::type(),
                app(HandlerDiscovery::class)->discover(),
            );
        } catch (Throwable) {
            return [];
        }
    }

    private function busConnection(): AMQPStreamConnection
    {
        $cfg = (array) config('microservice.bus.connection', []);
        $timeout = (float) ($cfg['connection_timeout'] ?? 10);

        return new AMQPStreamConnection(
            $cfg['host'] ?? '127.0.0.1',
            (int) ($cfg['port'] ?? 5672),
            $cfg['user'] ?? 'guest',
            $cfg['password'] ?? 'guest',
            $cfg['vhost'] ?? '/',
            connection_timeout: $timeout,
            read_write_timeout: $timeout,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $services
     */
    private function summarize(array $services): array
    {
        $count = static fn (string $status) => count(array_filter(
            $services,
            static fn (array $s) => $s['status'] === $status,
        ));

        return [
            'total' => count($services),
            'healthy' => $count('healthy'),
            'missing' => $count('missing'),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $services
     * @param  array<string, array<string, mixed>>  $dependencies
     */
    private function overallStatus(array $services, array $dependencies): string
    {
        foreach ($dependencies as $dependency) {
            if (($dependency['critical'] ?? false) && ($dependency['status'] ?? null) !== 'up') {
                return self::STATUS_UNHEALTHY;
            }
        }

        if (array_any($services, static fn (array $s) => $s['status'] === 'missing')) {
            return self::STATUS_UNHEALTHY;
        }

        $deadLetters = $dependencies['rabbitmq']['dead_letters'] ?? [];

        $degraded = array_any($services, static fn (array $s) => ($s['circuit']['state'] ?? null) === 'open')
            || array_any($dependencies, static fn (array $d) => ($d['status'] ?? null) !== 'up')
            || array_any((array) $deadLetters, static fn (int $depth) => $depth > 0);

        return $degraded ? self::STATUS_DEGRADED : self::STATUS_HEALTHY;
    }

    private function safeCache(callable $callback, mixed $default = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $default;
        }
    }

    private function elapsedMs(float $start): float
    {
        return round((microtime(true) - $start) * 1000, 2);
    }
}
