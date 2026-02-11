<?php

declare(strict_types=1);

namespace Jurager\Microservice\Registry;

use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Concerns\InteractsWithRedis;

class ManifestRegistry
{
    use InteractsWithRedis;

    public const EXCLUDED_ACTION_KEYS = [
        'uses', 'controller', 'middleware', 'as', 'prefix', 'namespace',
        'where', 'domain', 'excluded_middleware', 'withoutMiddleware',
    ];

    /**
     * Build the manifest payload for the current service.
     */
    public function build(): array
    {
        return [
            'service' => config('microservice.name'),
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

        $this->redis()->setex($prefix . "manifest:$service", $ttl, json_encode($manifest));
        $this->redis()->sadd($prefix . 'manifests', $service);
    }

    /**
     * Collect routes from the current application matching the configured prefix.
     */
    protected function collectRoutes(): array
    {
        $prefix = config('microservice.manifest.prefix', 'api');
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if ($prefix && $uri !== $prefix && !str_starts_with($uri, $prefix . '/')) {
                continue;
            }

            $metadata = array_diff_key(
                $route->getAction(),
                array_flip(self::EXCLUDED_ACTION_KEYS),
            );

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $routes[] = array_filter([
                    'method' => $method,
                    'uri' => '/' . ltrim($uri, '/'),
                    'name' => $route->getName(),
                    ...$metadata,
                ], static fn ($value) => $value !== null);
            }
        }

        return $routes;
    }
}
