<?php

declare(strict_types=1);

namespace Jurager\Microservice\Registry;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Contracts\Routing\Registrar;
use Jurager\Microservice\Concerns\InteractsWithRedis;

class ManifestRegistry
{
    use InteractsWithRedis;

    public function __construct(
        private readonly Factory $redisFactory,
        private readonly Registrar $router,
    ) {
    }

    public function build(): array
    {
        return [
            'service' => config('microservice.name'),
            'base_url' => config('app.url'),
            'timeout' => config('microservice.manifest.timeout'),
            'routes' => $this->collectRoutes(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Get a stored manifest from Redis, or null if not present.
     */
    public function get(string $service): ?array
    {
        $raw = $this->redis()->get($this->redisPrefix()."manifest:$service");

        return $raw ? json_decode($raw, true) : null;
    }

    /**
     * Store a manifest in Redis.
     */
    public function store(array $manifest): void
    {
        $service = $manifest['service'] ?? null;

        if ($service === null) {
            return;
        }

        $prefix = $this->redisPrefix();

        $manifest['synced_at'] = now()->toIso8601String();

        $this->redis()->pipeline(function ($pipe) use ($prefix, $service, $manifest): void {
            $pipe->set($prefix."manifest:$service", json_encode($manifest));
            $pipe->sadd($prefix.'manifests', $service);
        });
    }

    /**
     * Refresh manifest without touching routes.
     */
    public function touch(string $service): void
    {
        $key = $this->redisPrefix()."manifest:$service";
        $raw = $this->redis()->get($key);

        if (! $raw) {
            return;
        }

        $manifest = json_decode($raw, true);
        $manifest['synced_at'] = now()->toIso8601String();

        $this->redis()->set($key, json_encode($manifest));
    }

    /**
     * Remove a manifest and its registration from Redis.
     */
    public function remove(string $service): void
    {
        $prefix = $this->redisPrefix();

        $this->redis()->pipeline(function ($pipe) use ($prefix, $service): void {
            $pipe->del($prefix."manifest:$service");
            $pipe->srem($prefix.'manifests', $service);
        });
    }

    /**
     * Collect routes from the current application matching the configured prefix.
     */
    protected function collectRoutes(): array
    {
        $prefixes = $this->resolvePrefixes();
        $routes = [];

        foreach ($this->router->getRoutes() as $route) {
            $uri = $route->uri();

            if (! empty($prefixes) && ! $this->matchesPrefix($uri, $prefixes)) {
                continue;
            }

            $metadata = $route->getMetadata() ?? [];

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $routes[] = array_filter([
                    'method' => $method,
                    'uri' => '/'.ltrim($uri, '/'),
                    'name' => $route->getName(),
                    ...$metadata,
                ], static fn ($value) => $value !== null);
            }
        }

        return $routes;
    }

    /**
     * Normalize manifest prefix to an array of non-empty strings.
     *
     * @return string[]
     */
    protected function resolvePrefixes(): array
    {
        $raw = config('microservice.manifest.prefix', '');

        $items = is_array($raw)
            ? $raw
            : array_map('trim', explode(',', (string) $raw));

        return array_values(array_filter($items, fn ($p) => $p !== ''));
    }

    /**
     * Check if a service manifest exists in Redis.
     */
    public function has(string $service): bool
    {
        return (bool) $this->redis()->exists($this->redisPrefix().'manifest:'.$service);
    }

    /**
     * Check if a URI matches any of the given prefixes.
     *
     * @param  string[]  $prefixes
     */
    protected function matchesPrefix(string $uri, array $prefixes): bool
    {
        return array_any($prefixes, fn ($prefix) => $uri === $prefix || str_starts_with($uri, $prefix.'/'));

    }
}
