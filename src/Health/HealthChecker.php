<?php

declare(strict_types=1);

namespace Jurager\Microservice\Health;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Jurager\Microservice\Client\CircuitBreaker;
use Jurager\Microservice\Client\ServiceDiscovery;
use Jurager\Microservice\Health\Contracts\DependencyCheck;
use Jurager\Microservice\Manifest\ManifestRepository;
use Throwable;

/**
 * Build health reports from dependency checks and service status.
 */
class HealthChecker
{
    public const string STATUS_HEALTHY = 'healthy';

    public const string STATUS_DEGRADED = 'degraded';

    public const string STATUS_UNHEALTHY = 'unhealthy';

    /**
     * @param  iterable<DependencyCheck>  $checks
     * @param  int  $cacheTtl  Seconds the detailed report is reused; zero builds it every time.
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly ManifestRepository $manifests,
        private readonly CircuitBreaker $breaker,
        private readonly ServiceDiscovery $discovery,
        private readonly iterable $checks,
        #[Config('microservice.name', 'app')] private readonly string $name = 'app',
        #[Config('microservice.version')] private readonly ?string $version = null,
        #[Config('app.env', 'production')] private readonly string $environment = 'production',
        #[Config('microservice.health.cache_ttl', 0)] private readonly int $cacheTtl = 0,
    ) {
    }

    /**
     * Get the cached health report for this instance.
     *
     * @param  string|null  $only  Limit the report to one service.
     * @param  bool  $verbose  Include base URLs, timeouts and dependency errors.
     */
    public function report(?string $only = null, bool $verbose = false): array
    {
        if ($this->cacheTtl <= 0) {
            return $this->build($only, $verbose);
        }

        $key = 'microservice:health:'.gethostname().':'.($only ?? 'all').':'.($verbose ? 'v' : 'n');

        try {
            return $this->cache->remember($key, $this->cacheTtl, fn (): array => $this->build($only, $verbose));
        } catch (Throwable) {
            return $this->build($only, $verbose);
        }
    }

    /**
     * Determine if critical dependencies are up and a service manifest is available.
     */
    public function readiness(): array
    {
        $dependencies = $this->dependencies(critical: true);
        $services = $this->services();
        $loaded = count(array_filter($services, static fn (array $service): bool => $service['status'] !== 'missing'));

        $ready = array_all($dependencies, static fn (DependencyStatus $status): bool => $status->up)
            && ($services === [] || $loaded > 0);

        return [
            'status' => $ready ? self::STATUS_HEALTHY : self::STATUS_UNHEALTHY,
            'checked_at' => Carbon::now()->toIso8601String(),
            ...array_map(static fn (DependencyStatus $status): array => $status->toArray(), $dependencies),
            'manifests_loaded' => $loaded,
        ];
    }

    /**
     * Manifest and breaker state of each known service.
     *
     * @return array<string, array<string, mixed>>
     */
    public function services(?string $only = null, bool $verbose = false): array
    {
        $names = $this->discovery->services();

        if ($only !== null) {
            $names = in_array($only, $names, true) ? [$only] : [];
        }

        $services = [];

        foreach ($names as $service) {
            $manifest = $this->quietly(fn () => $this->manifests->get($service));
            $circuit = $this->quietly(fn () => $this->breaker->state($service));

            $services[$service] = array_filter(match (true) {
                $manifest === null => ['status' => 'missing', 'routes_count' => 0, 'circuit' => $circuit],
                default => [
                    'status' => 'healthy',
                    'synced_at' => $manifest['synced_at'] ?? null,
                    'age_seconds' => self::age($manifest['synced_at'] ?? null),
                    'routes_count' => count($manifest['routes'] ?? []),
                    'circuit' => $circuit,
                    'base_url' => $verbose ? ($manifest['base_url'] ?? null) : null,
                    'timeout' => $verbose ? ($manifest['timeout'] ?? null) : null,
                ],
            }, static fn (mixed $value): bool => $value !== null);
        }

        return $services;
    }

    /**
     * HTTP status answering a report of the given overall status.
     */
    public function httpStatus(string $status): int
    {
        return $status === self::STATUS_UNHEALTHY ? 503 : 200;
    }

    private function build(?string $only, bool $verbose): array
    {
        $services = $this->services($only, $verbose);
        $dependencies = $this->dependencies();

        return array_filter([
            'status' => $this->status($services, $dependencies),
            'gateway' => $this->name,
            'instance' => array_filter([
                'hostname' => gethostname() ?: null,
                'version' => $this->version,
                'environment' => $this->environment,
                'uptime_seconds' => self::uptime(),
            ], static fn (mixed $value): bool => $value !== null),
            'checked_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'total' => count($services),
                'healthy' => count(array_filter($services, static fn (array $service): bool => $service['status'] === 'healthy')),
                'missing' => count(array_filter($services, static fn (array $service): bool => $service['status'] === 'missing')),
            ],
            'dependencies' => array_map(static fn (DependencyStatus $status): array => $status->toArray($verbose), $dependencies),
            'services' => $services,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Run the enabled checks, by name.
     *
     * @return array<string, DependencyStatus>
     */
    private function dependencies(bool $critical = false): array
    {
        $statuses = [];

        foreach ($this->checks as $check) {
            if ($check->enabled() && (! $critical || $check->critical())) {
                $statuses[$check->name()] = $check->check();
            }
        }

        return $statuses;
    }

    /**
     * @param  array<string, array<string, mixed>>  $services
     * @param  array<string, DependencyStatus>  $dependencies
     */
    private function status(array $services, array $dependencies): string
    {
        $critical = [];

        foreach ($this->checks as $check) {
            $critical[$check->name()] = $check->critical();
        }

        foreach ($dependencies as $name => $dependency) {
            if (! $dependency->up && ($critical[$name] ?? false)) {
                return self::STATUS_UNHEALTHY;
            }
        }

        if (array_any($services, static fn (array $service): bool => $service['status'] === 'missing')) {
            return self::STATUS_UNHEALTHY;
        }

        $degraded = array_any($services, static fn (array $service): bool => ($service['circuit']['state'] ?? null) === CircuitBreaker::OPEN)
            || array_any($dependencies, static fn (DependencyStatus $dependency): bool => ! $dependency->up || $dependency->degraded);

        return $degraded ? self::STATUS_DEGRADED : self::STATUS_HEALTHY;
    }

    /**
     * Run a cache read that must not fail the report when the cache is down.
     */
    private function quietly(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Seconds since a manifest was synced.
     */
    private static function age(mixed $syncedAt): ?int
    {
        if (! is_string($syncedAt) || $syncedAt === '') {
            return null;
        }

        try {
            return max(0, (int) Carbon::parse($syncedAt)->diffInSeconds(Carbon::now()));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Container uptime on Linux; null where /proc is not available.
     */
    private static function uptime(): ?int
    {
        $contents = is_readable('/proc/uptime') ? @file_get_contents('/proc/uptime') : false;

        return $contents === false ? null : (int) (float) strtok($contents, ' ');
    }
}
