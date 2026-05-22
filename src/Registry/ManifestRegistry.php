<?php

declare(strict_types=1);

namespace Jurager\Microservice\Registry;

use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Concerns\InteractsWithRedis;

class ManifestRegistry
{
    use InteractsWithRedis;

    public const array EXCLUDED_ACTION_KEYS = [
        'uses', 'controller', 'middleware', 'as', 'prefix', 'namespace',
        'where', 'domain', 'excluded_middleware', 'withoutMiddleware',
    ];

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
     * Store a manifest in Redis.
     */
    public function store(array $manifest): void
    {
        $service = $manifest['service'] ?? null;

        if ($service === null) {
            return;
        }

        $prefix = $this->redisPrefix();
        $ttl = config('microservice.manifest.ttl', 300);

        $manifest['synced_at'] = now()->toIso8601String();

        $this->redis()->setex($prefix."manifest:$service", $ttl, json_encode($manifest));
        $this->redis()->sadd($prefix.'manifests', $service);
    }

    /**
     * Collect routes from the current application matching the configured prefix.
     */
    protected function collectRoutes(): array
    {
        $prefixes = $this->resolvePrefixes();
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! empty($prefixes) && ! $this->matchesPrefix($uri, $prefixes)) {
                continue;
            }

            $metadata = array_merge(
                $route->defaults ?? [],
                array_diff_key(
                    $route->getAction(),
                    array_flip(self::EXCLUDED_ACTION_KEYS),
                ),
            );

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
     * Normalize manifest.prefix to an array of non-empty strings.
     * Accepts a string (comma-separated) or an array.
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
     * Check if a URI matches any of the given prefixes.
     *
     * @param  string[]  $prefixes
     */
    protected function matchesPrefix(string $uri, array $prefixes): bool
    {
        return array_any($prefixes, fn ($prefix) => $uri === $prefix || str_starts_with($uri, $prefix . '/'));

    }
}
