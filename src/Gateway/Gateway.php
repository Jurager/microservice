<?php

declare(strict_types=1);

namespace Jurager\Microservice\Gateway;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Jurager\Microservice\Http\Controllers\ProxyController;
use Jurager\Microservice\Manifest\ManifestRepository;
use Jurager\Microservice\Manifest\ManifestRoute;
use Psr\Log\LoggerInterface;
use Throwable;

class Gateway
{
    /** @var list<array{group: array<string, mixed>, manifests: ManifestRepository, define: Closure(array<string, array>): list<Route>, version: string|false|null, routes: list<Route>}> */
    private array $registrations = [];

    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Register manifest routes as Laravel routes pointing to the proxy controller.
     *
     * @param  string[]|null  $services
     */
    public static function routes(?Closure $overrides = null, ?array $services = null, ?string $controller = null, ?ManifestRepository $manifests = null): void
    {
        $builder = new GatewayRoutes();

        if ($overrides) {
            $overrides($builder);
        }

        app(self::class)->register($manifests ?? app(ManifestRepository::class), $builder, $services, $controller ?? ProxyController::class);
    }

    /**
     * Register the routes again once their manifests change.
     */
    public function refresh(): void
    {
        $changed = [];

        foreach ($this->registrations as $index => $registration) {
            try {
                $version = $registration['manifests']->version();

                if ($version !== $registration['version']) {
                    $changed[$index] = [$version, $registration['manifests']->all()];
                }
            } catch (Throwable) {
                // Keep the routes while the cache is down.
            }
        }

        if ($changed !== []) {
            $this->rebuild($changed);
        }
    }

    /**
     * Whether gateway routes are registered or loaded from the route cache.
     */
    public function registered(): bool
    {
        return $this->registrations !== []
            || array_any($this->router->getRoutes()->getRoutes(), static fn (Route $route): bool => $route->getAction('_service') !== null);
    }

    /**
     * @param  string[]|null  $services
     */
    private function register(ManifestRepository $manifests, GatewayRoutes $builder, ?array $services, string $controller): void
    {
        $stack = $this->router->getGroupStack();
        $define = fn (array $stored): array => $this->define($stored, $builder, $services, $controller);

        try {
            $version = $manifests->version();
            $stored = $manifests->all();
        } catch (Throwable $e) {
            $this->logger->warning('Manifests are unreachable, no gateway routes registered.', ['error' => $e->getMessage()]);

            // Unlike null, matches no stored version.
            $version = false;
            $stored = [];
        }

        $this->registrations[] = [
            'group' => end($stack) ?: [],
            'manifests' => $manifests,
            'define' => $define,
            'version' => $version,
            'routes' => $define($stored),
        ];
    }

    /**
     * @param  array<int, array{string|null, array<string, array>}>  $changed
     */
    private function rebuild(array $changed): void
    {
        $owners = [];

        foreach (array_keys($changed) as $index) {
            foreach ($this->registrations[$index]['routes'] as $route) {
                $owners[spl_object_id($route)] = $index;
            }
        }

        $current = $this->router->getRoutes();

        // The router adds new routes to this collection.
        $this->router->setRoutes($routes = new RouteCollection());

        foreach ($current->getRoutes() as $route) {
            $index = $owners[spl_object_id($route)] ?? null;

            if ($index === null) {
                $routes->add($route);
            } elseif (isset($changed[$index])) {
                $this->redefine($index, ...$changed[$index]);

                unset($changed[$index]);
            }
        }

        // Registrations that had no routes go last.
        foreach ($changed as $index => [$version, $stored]) {
            $this->redefine($index, $version, $stored);
        }

        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        // For the application Octane clones each request from.
        $this->container->instance('routes', $routes);
    }

    /**
     * @param  array<string, array>  $stored
     */
    private function redefine(int $index, ?string $version, array $stored): void
    {
        $registration = $this->registrations[$index];
        $routes = [];

        $this->router->group($registration['group'], function () use ($registration, $stored, &$routes): void {
            $routes = ($registration['define'])($stored);
        });

        $this->registrations[$index] = [...$registration, 'version' => $version, 'routes' => $routes];
    }

    /**
     * Register the routes of the manifests in the current route group.
     *
     * @param  array<string, array>  $stored
     * @param  string[]|null  $services
     * @return list<Route>
     */
    private function define(array $stored, GatewayRoutes $builder, ?array $services, string $controller): array
    {
        $reservedKeys = array_flip(['method', 'methods', 'uri', 'name']);

        $overrideMap = $builder->getOverrides();
        $serviceMiddleware = $builder->getServiceMiddleware();
        $routeMiddleware = $builder->getRouteMiddleware();
        $servicePrefixes = $builder->getServicePrefixes();

        $defined = [];

        foreach ($stored as $manifest) {
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
                    ManifestRoute::methods($routeData),
                    $serviceUri,
                    $overrideMap[$service] ?? [],
                    $routeMiddleware[$service] ?? [],
                    [$controller, 'handle'],
                    $serviceMiddleware[$service] ?? [],
                );

                $name = empty($routeData['name']) ? null : $service.'.'.$routeData['name'];

                foreach ($groups as $group) {
                    $route = $this->router->match($group['methods'], $uri, $group['action']);

                    $route->setAction([
                        ...$route->getAction(),
                        '_service' => $service,
                        '_service_uri' => $serviceUri,
                        '_service_prefix' => $prefix,
                        'metadata' => $metadata,
                    ]);

                    if ($name !== null && ! isset($claimed[$name])) {
                        $route->name($name);
                        $claimed[$name] = true;
                    }

                    if (! empty($group['middleware'])) {
                        $route->middleware($group['middleware']);
                    }

                    $defined[] = $route;
                }
            }
        }

        return $defined;
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
            $methods = ManifestRoute::methods($routeData);
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
