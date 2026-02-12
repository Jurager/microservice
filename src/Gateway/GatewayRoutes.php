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

    protected ?string $lastRouteKey = null;

    public function service(string $name): static
    {
        $this->currentService = $name;
        $this->lastRouteKey = null;

        return $this;
    }

    public function prefix(string $prefix): static
    {
        $this->servicePrefixes[$this->currentService] = $prefix;

        return $this;
    }

    public function middleware(array $middleware): static
    {
        if ($this->lastRouteKey !== null) {
            $this->routeMiddleware[$this->currentService][$this->lastRouteKey] = $middleware;
        } else {
            $this->serviceMiddleware[$this->currentService] = $middleware;
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

    protected function add(string $method, string $uri, array|Closure|null $action): static
    {
        $uri = '/'.ltrim($uri, '/');

        $this->lastRouteKey = $method.' '.$uri;

        if ($action !== null) {
            $this->overrides[$this->currentService][$this->lastRouteKey] = $action;
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
