<?php

declare(strict_types=1);

namespace Jurager\Microservice\Gateway;

use Closure;
use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Http\Controllers\ProxyController;
use Jurager\Microservice\Http\Middleware\Idempotency;
use Jurager\Microservice\Registry\ManifestRegistry;
use Jurager\Microservice\Registry\RouteRegistry;

class Gateway
{
    /**
     * Register manifest routes as Laravel routes pointing to the proxy controller.
     *
     * @param  string[]|null  $services
     */
    public static function routes(?Closure $overrides = null, ?array $services = null, ?string $controller = null): void
    {
        $registry = app(RouteRegistry::class);
        $controller ??= ProxyController::class;

        $reservedKeys = array_flip(['method', 'uri', 'name', ...ManifestRegistry::EXCLUDED_ACTION_KEYS]);

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

            foreach ($manifest['routes'] ?? [] as $routeData) {
                $serviceUri = $routeData['uri'];
                $uri = ltrim($routeData['uri'], '/');
                $key = $routeData['method'].' '.$routeData['uri'];

                $prefix = trim($servicePrefixes[$service] ?? $service, '/');

                if ($prefix !== '') {
                    $uri = $prefix.'/'.$uri;
                }

                $action = $overrideMap[$service][$key] ?? [$controller, 'handle'];

                $route = Route::match([$routeData['method']], $uri, $action);

                $metadata = array_diff_key($routeData, $reservedKeys);

                $route->setAction([
                    ...$route->getAction(),
                    '_service' => $service,
                    '_service_uri' => $serviceUri,
                    '_service_prefix' => $prefix,
                    ...$metadata,
                ]);

                if (! empty($routeData['name'])) {
                    $route->name($service.'.'.$routeData['name']);
                }

                $middleware = [
                    Idempotency::class,
                    ...($serviceMiddleware[$service] ?? []),
                    ...($routeMiddleware[$service][$key] ?? []),
                ];

                if (! empty($middleware)) {
                    $route->middleware($middleware);
                }
            }
        }
    }
}
