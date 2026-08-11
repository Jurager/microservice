<?php

declare(strict_types=1);

namespace Jurager\Microservice\Registry;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Routing\Registrar;

class ManifestRegistry
{
    public function __construct(
        private readonly Cache $cache,
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
        $raw = $this->cache->get("microservice:manifest:$service");

        if (!$raw) {
            return null;
        }

        return is_array($raw) ? $raw : json_decode($raw, true);
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

        $manifest['synced_at'] = now()->toIso8601String();

        $this->cache->put("microservice:manifest:$service", $manifest, $this->ttl());

        $lock = $this->cache->lock('microservice:manifests_lock', 10);
        
        try {
            $lock->block(5, function () use ($service) {
                $services = $this->cache->get('microservice:manifests', []);
                if (!in_array($service, $services, true)) {
                    $services[] = $service;
                    $this->cache->put('microservice:manifests', $services);
                }
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            // Lock timeout, array might not be updated, but manifest is saved
        }
    }

    /**
     * Refresh manifest without touching routes.
     */
    public function touch(string $service): void
    {
        $key = "microservice:manifest:$service";
        $manifest = $this->cache->get($key);

        if (! $manifest) {
            return;
        }

        $manifest = is_array($manifest) ? $manifest : json_decode($manifest, true);
        $manifest['synced_at'] = now()->toIso8601String();

        $this->cache->put($key, $manifest, $this->ttl());
    }

    /**
     * Manifest lifetime in the cache, or null to keep it until it is replaced.
     */
    private function ttl(): ?int
    {
        $ttl = (int) config('microservice.manifest.ttl', 0);

        return $ttl > 0 ? $ttl : null;
    }

    /**
     * Remove a manifest and its registration from Redis.
     */
    public function remove(string $service): void
    {
        $this->cache->forget("microservice:manifest:$service");

        $lock = $this->cache->lock('microservice:manifests_lock', 10);

        try {
            $lock->block(5, function () use ($service) {
                $services = $this->cache->get('microservice:manifests', []);
                $index = array_search($service, $services, true);
                if ($index !== false) {
                    unset($services[$index]);
                    $this->cache->put('microservice:manifests', array_values($services));
                }
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            // Ignore lock timeout
        }
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

            $methods = array_values(array_diff($route->methods(), ['HEAD']));

            if ($methods === []) {
                continue;
            }

            $metadata = $route->getMetadata() ?? [];

            $routes[] = array_filter([
                'methods' => $methods,
                'uri' => '/'.ltrim($uri, '/'),
                'name' => $route->getName(),
                ...$metadata,
            ], static fn ($value) => $value !== null);
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
        return $this->cache->has("microservice:manifest:$service");
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
