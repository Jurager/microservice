<?php

declare(strict_types=1);

namespace Jurager\Microservice\Manifest;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Routing\Route;

/**
 * Builds the manifest this service publishes: where it answers and which routes it exposes.
 */
class ManifestBuilder
{
    /**
     * @param  string  $service  This service's name.
     * @param  string|null  $baseUrl  The URL the service answers at.
     * @param  int  $timeout  Request timeout clients should use.
     * @param  string|list<string>  $prefix  URI prefix(es) of the exposed routes, comma-separated or a list; empty exposes all.
     */
    public function __construct(
        private readonly Registrar $router,
        #[Config('microservice.name', 'app')] private readonly string $service = 'app',
        #[Config('app.url')] private readonly ?string $baseUrl = null,
        #[Config('microservice.manifest.timeout', 30)] private readonly int $timeout = 30,
        #[Config('microservice.manifest.prefix', '')] private readonly string|array $prefix = '',
    ) {
    }

    /** @return array{service: string, base_url: string|null, timeout: int, routes: list<array<string, mixed>>, timestamp: string} */
    public function build(): array
    {
        return [
            'service' => $this->service,
            'base_url' => $this->baseUrl,
            'timeout' => $this->timeout,
            'routes' => $this->routes(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function routes(): array
    {
        $prefixes = $this->prefixes();
        $routes = [];

        /** @var Route $route */
        foreach ($this->router->getRoutes() as $route) {
            $uri = $route->uri();
            $methods = array_values(array_diff($route->methods(), ['HEAD']));

            if ($methods === [] || ($prefixes !== [] && ! self::under($uri, $prefixes))) {
                continue;
            }

            $routes[] = array_filter([
                'methods' => $methods,
                'uri' => '/'.ltrim($uri, '/'),
                'name' => $route->getName(),
                ...($route->getMetadata() ?? []),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $routes;
    }

    /** @return list<string> */
    private function prefixes(): array
    {
        $items = is_array($this->prefix) ? $this->prefix : explode(',', $this->prefix);

        return array_values(array_filter(array_map('trim', $items), static fn (string $prefix): bool => $prefix !== ''));
    }

    /** @param  list<string>  $prefixes */
    private static function under(string $uri, array $prefixes): bool
    {
        return array_any($prefixes, static fn (string $prefix): bool => $uri === $prefix || str_starts_with($uri, $prefix.'/'));
    }
}
