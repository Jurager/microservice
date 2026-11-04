<?php

declare(strict_types=1);

namespace Jurager\Microservice\Gateway;

use Closure;

class GatewayRoutes
{
    protected array $overrides = [];

    protected array $serviceMiddleware = [];

    protected array $routeMiddleware = [];

    protected array $servicePrefixes = [];

    protected ?string $currentService = null;

    /** @var string[] Keys of the routes touched by the last method call. */
    protected array $lastRouteKeys = [];

    public function service(string $name): static
    {
        $this->currentService = $name;
        $this->lastRouteKeys = [];

        return $this;
    }

    public function prefix(string $prefix): static
    {
        $this->servicePrefixes[$this->currentService] = $prefix;

        return $this;
    }

    public function middleware(array $middleware): static
    {
        if ($this->lastRouteKeys === []) {
            $this->serviceMiddleware[$this->currentService] = $middleware;

            return $this;
        }

        foreach ($this->lastRouteKeys as $key) {
            $this->routeMiddleware[$this->currentService][$key] = $middleware;
        }

        return $this;
    }

    public function get(string $uri, array|Closure|null $action = null): static
    {
        return $this->add('GET', $uri, $action);
    }

    public function post(string $uri, array|Closure|null $action = null): static
    {
        return $this->add('POST', $uri, $action);
    }

    public function put(string $uri, array|Closure|null $action = null): static
    {
        return $this->add('PUT', $uri, $action);
    }

    public function patch(string $uri, array|Closure|null $action = null): static
    {
        return $this->add('PATCH', $uri, $action);
    }

    public function delete(string $uri, array|Closure|null $action = null): static
    {
        return $this->add('DELETE', $uri, $action);
    }

    /**
     * Target several methods of the same route at once.
     *
     * @param  string[]  $methods
     */
    public function match(array $methods, string $uri, array|Closure|null $action = null): static
    {
        return $this->add($methods, $uri, $action);
    }

    /**
     * @param  string|string[]  $methods
     */
    protected function add(array|string $methods, string $uri, array|Closure|null $action): static
    {
        $uri = '/'.ltrim($uri, '/');

        $this->lastRouteKeys = array_map(
            static fn (string $method) => strtoupper($method).' '.$uri,
            (array) $methods,
        );

        if ($action !== null) {
            foreach ($this->lastRouteKeys as $key) {
                $this->overrides[$this->currentService][$key] = $action;
            }
        }

        return $this;
    }

    public function getOverrides(): array
    {
        return $this->overrides;
    }

    public function getServiceMiddleware(): array
    {
        return $this->serviceMiddleware;
    }

    public function getRouteMiddleware(): array
    {
        return $this->routeMiddleware;
    }

    public function getServicePrefixes(): array
    {
        return $this->servicePrefixes;
    }
}
