<?php

declare(strict_types=1);

namespace Jurager\Microservice\Registry;

use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;
use Throwable;

class RouteRegistry
{
    public function __construct(
        private readonly Cache $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Get all registered manifests from Redis.
     *
     * @return array<string, array{service: string, routes: array, timestamp: string}>
     */
    public function getAllManifests(): array
    {
        try {
            $services = $this->cache->get('microservice:manifests', []);

            if (!$services || !is_array($services)) {
                return [];
            }

            $keys = array_map(static fn (string $s) => "microservice:manifest:$s", $services);

            $raws = $this->cache->many($keys);

        } catch (Throwable $e) {
            $this->logger->warning('RouteRegistry: manifests are unreachable, no gateway routes registered.', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $manifests = [];

        foreach ($raws as $raw) {
            if ($raw === null || $raw === false) {
                continue;
            }

            $manifest = is_array($raw) ? $raw : json_decode($raw, true);

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
     * @return array<int, array{service: string, methods: string[], uri: string, name: string|null}>
     */
    public function getAllRoutes(): array
    {
        $routes = [];

        foreach ($this->getAllManifests() as $manifest) {
            foreach ($manifest['routes'] ?? [] as $route) {
                $routes[] = [
                    'service' => $manifest['service'],
                    'methods' => self::methods($route),
                    'uri' => $route['uri'],
                    'name' => $route['name'] ?? null,
                ];
            }
        }

        return $routes;
    }

    /**
     * HTTP methods answered by a manifest route entry.
     *
     * @param  array<string, mixed>  $route
     * @return string[]
     */
    public static function methods(array $route): array
    {
        $methods = $route['methods'] ?? $route['method'] ?? [];

        return array_values(array_map(
            static fn ($method) => strtoupper((string) $method),
            (array) $methods,
        ));
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
                if (! in_array($method, self::methods($route), true)) {
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

        return $regex !== '' && preg_match('#^'.$regex.'$#', $uri);
    }
}
