<?php

declare(strict_types=1);

namespace Jurager\Microservice\Gateway;

use Closure;
use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Http\Controllers\ProxyController;
use Jurager\Microservice\Http\Middleware\Idempotency;
use Jurager\Microservice\Registry\RouteRegistry;

class Gateway
{
    /**
     * Register manifest routes as Laravel routes pointing to the proxy controller.
     *
     * @param  string[]|null  $services
     */
    public static function routes(?Closure $overrides = null, ?array $services = null, ?string $controller = null, ?RouteRegistry $registry = null): void
    {
        $registry ??= app(RouteRegistry::class);
        $controller ??= ProxyController::class;

        $reservedKeys = array_flip(['method', 'methods', 'uri', 'name']);

        $builder = new GatewayRoutes();

        if ($overrides) {
            $overrides($builder);
        }

        $overrideMap = $builder->getOverrides();
        $serviceMiddleware = $builder->getServiceMiddleware();
        $routeMiddleware = $builder->getRouteMiddleware();
        $servicePrefixes = $builder->getServicePrefixes();

        foreach ($registry->getAllManifests() as $manifest) {
            $service = $manifest['service'];

            if ($services !== null && ! in_array($service, $services, true)) {
                continue;
            }

            $claimed = [];

            foreach (static::normalize($manifest['routes'] ?? []) as $routeData) {
                $serviceUri = $routeData['uri'];
                $uri = ltrim($serviceUri, '/');

                $prefix = trim($servicePrefixes[$service] ?? $service, '/');

                if ($prefix !== '') {
                    $uri = $prefix.'/'.$uri;
                }

                $metadata = array_diff_key($routeData, $reservedKeys);

                $groups = static::group(
                    RouteRegistry::methods($routeData),
                    $serviceUri,
                    $overrideMap[$service] ?? [],
                    $routeMiddleware[$service] ?? [],
                    [$controller, 'handle'],
                    [Idempotency::class, ...($serviceMiddleware[$service] ?? [])],
                );

                $name = empty($routeData['name']) ? null : $service.'.'.$routeData['name'];

                foreach ($groups as $group) {
                    $route = Route::match($group['methods'], $uri, $group['action']);

                    $route->setAction([
                        ...$route->getAction(),
                        '_service' => $service,
                        '_service_uri' => $serviceUri,
                        '_service_prefix' => $prefix,
                        ...$metadata,
                    ]);

                    if ($name !== null && ! isset($claimed[$name])) {
                        $route->name($name);
                        $claimed[$name] = true;
                    }

                    if (! empty($group['middleware'])) {
                        $route->middleware($group['middleware']);
                    }
                }
            }
        }
    }

    /**
     * Merge manifest entries that describe the same route.
     *
     * @param  array<int, array<string, mixed>>  $routes
     * @return array<int, array<string, mixed>>
     */
    protected static function normalize(array $routes): array
    {
        $reservedKeys = array_flip(['method', 'methods']);

        $normalized = [];
        $byName = [];

        foreach ($routes as $routeData) {
            $methods = RouteRegistry::methods($routeData);
            $rest = array_diff_key($routeData, $reservedKeys);
            $name = (string) ($rest['name'] ?? '');

            $merged = false;

            foreach ($byName[$name] ?? [] as $index) {
                if ($normalized[$index]['rest'] == $rest) {
                    $normalized[$index]['methods'] = array_values(array_unique([
                        ...$normalized[$index]['methods'],
                        ...$methods,
                    ]));

                    $merged = true;

                    break;
                }
            }

            if ($merged) {
                continue;
            }

            $index = count($normalized);
            $normalized[$index] = ['methods' => $methods, 'rest' => $rest];

            if ($name !== '') {
                $byName[$name][] = $index;
            }
        }

        return array_map(
            static fn (array $entry) => ['methods' => $entry['methods'], ...$entry['rest']],
            $normalized,
        );
    }

    /**
     * Split the methods of a manifest entry into groups sharing the same actios and middleware.
     *
     * @param  string[]  $methods
     * @param  array<string, array|Closure>  $overrides  Actions keyed by "METHOD /uri".
     * @param  array<string, array>  $middlewareOverrides  Middleware keyed by "METHOD /uri".
     * @param  array{class-string, string}  $defaultAction
     * @param  array<int, mixed>  $baseMiddleware
     * @return array<int, array{methods: string[], action: array|Closure, middleware: array<int, mixed>}>
     */
    protected static function group(
        array $methods,
        string $serviceUri,
        array $overrides,
        array $middlewareOverrides,
        array $defaultAction,
        array $baseMiddleware,
    ): array {
        $groups = [];

        foreach ($methods as $method) {
            $key = $method.' '.$serviceUri;

            $action = $overrides[$key] ?? $defaultAction;
            $middleware = [...$baseMiddleware, ...($middlewareOverrides[$key] ?? [])];

            foreach ($groups as $index => $group) {
                if ($group['action'] === $action && $group['middleware'] === $middleware) {
                    $groups[$index]['methods'][] = $method;

                    continue 2;
                }
            }

            $groups[] = ['methods' => [$method], 'action' => $action, 'middleware' => $middleware];
        }

        return $groups;
    }
}
