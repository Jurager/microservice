<?php

declare(strict_types=1);

namespace Jurager\Microservice\Registry;

use Jurager\Microservice\Concerns\InteractsWithRedis;

class RouteRegistry
{
    use InteractsWithRedis;

    /**
     * Get all registered manifests from Redis.
     *
     * @return array<string, array{service: string, routes: array, timestamp: string}>
     */
    public function getAllManifests(): array
    {
        $prefix = $this->redisPrefix();
        $services = $this->redis()->smembers($prefix.'manifests') ?: [];

        $manifests = [];

        foreach ($services as $service) {
            $raw = $this->redis()->get($prefix."manifest:$service");

            if ($raw === null || $raw === false) {
                continue;
            }

            $manifest = json_decode($raw, true);

            if (is_array($manifest) && isset($manifest['service'])) {
                $manifests[$manifest['service']] = $manifest;
            }
        }

        ksort($manifests);

        return $manifests;
    }

    /**
     * Get all registered routes across all services.
     *
     * @return array<int, array{service: string, method: string, uri: string, name: string|null}>
     */
    public function getAllRoutes(): array
    {
        $routes = [];

        foreach ($this->getAllManifests() as $manifest) {
            foreach ($manifest['routes'] ?? [] as $route) {
                $routes[] = [
                    'service' => $manifest['service'],
                    'method' => $route['method'],
                    'uri' => $route['uri'],
                    'name' => $route['name'] ?? null,
                ];
            }
        }

        return $routes;
    }

    /**
     * Resolve which service handles the given method + URI.
     */
    public function resolve(string $method, string $uri): ?array
    {
        $method = strtoupper($method);
        $uri = '/'.ltrim($uri, '/');

        foreach ($this->getAllManifests() as $manifest) {
            foreach ($manifest['routes'] ?? [] as $route) {
                if ($route['method'] !== $method) {
                    continue;
                }

                if ($this->matchUri($route['uri'], $uri)) {
                    return ['service' => $manifest['service'], ...$route];
                }
            }
        }

        return null;
    }

    /** @var array<string, string> Compiled regex patterns keyed by route pattern. */
    private array $compiledPatterns = [];

    /**
     * Clear cached compiled patterns. Call after manifests change in long-running processes.
     */
    public function clearPatternCache(): void
    {
        $this->compiledPatterns = [];
    }

    /**
     * Match a route pattern against a URI.
     * Supports Laravel-style {parameter} and {parameter?} placeholders.
     * Compiled patterns are cached in-memory for the lifetime of the process.
     */
    protected function matchUri(string $pattern, string $uri): bool
    {
        if ($pattern === $uri) {
            return true;
        }

        if (! isset($this->compiledPatterns[$pattern])) {
            $placeholder = '__PARAM__';
            $temp = preg_replace('/\{[^}]+\}/', $placeholder, $pattern);

            if ($temp === null) {
                $this->compiledPatterns[$pattern] = '';

                return false;
            }

            $quoted = preg_quote($temp, '#');
            $this->compiledPatterns[$pattern] = str_replace(preg_quote($placeholder, '#'), '[^/]+', $quoted);
        }

        $regex = $this->compiledPatterns[$pattern];

        return $regex !== '' && (bool) preg_match('#^'.$regex.'$#', $uri);
    }
}
