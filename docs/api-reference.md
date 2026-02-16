---
title: Api Reference
weight: 90
---

## API Reference

## Client

`Jurager\Microservice\Client\ServiceClient`

- `service(string $name): PendingServiceRequest`
- `send(PendingServiceRequest $request): ServiceResponse`

`Jurager\Microservice\Client\PendingServiceRequest`

- `get(string $path)`
- `post(string $path, ?array $body = null)`
- `put(string $path, ?array $body = null)`
- `patch(string $path, ?array $body = null)`
- `delete(string $path)`
- `withHeaders(array $headers)`
- `withQuery(array $query)`
- `withBody(array $body)`
- `timeout(int $seconds)`
- `retries(int $retries)`
- `send(): ServiceResponse`

`Jurager\Microservice\Client\ServiceResponse`

- `status(): int`
- `ok(): bool`
- `failed(): bool`
- `json(?string $key = null, mixed $default = null): mixed`
- `body(): string`
- `header(string $name): ?string`
- `headers(): array`
- `toPsrResponse(): ResponseInterface`
- `throw(): static`

## Gateway

`Jurager\Microservice\Gateway\Gateway`

- `routes(?Closure $overrides = null, ?array $services = null, ?string $controller = null): void`

`Jurager\Microservice\Gateway\GatewayRoutes`

- `service(string $name)`
- `prefix(string $prefix)`
- `middleware(array $middleware)`
- `get(string $uri, array|Closure|null $action = null)`
- `post(string $uri, array|Closure|null $action = null)`
- `put(string $uri, array|Closure|null $action = null)`
- `patch(string $uri, array|Closure|null $action = null)`
- `delete(string $uri, array|Closure|null $action = null)`

## Registry

- `ManifestRegistry::build()`
- `ManifestRegistry::store(array $manifest)`
- `RouteRegistry::getAllManifests()`
- `RouteRegistry::getAllRoutes()`
- `RouteRegistry::resolve(string $method, string $uri)`
- `HealthRegistry::getAllHealth()`

## Endpoints

- `POST /microservice/manifest` (TrustService)
- `GET {SERVICE_HEALTH_ENDPOINT}` (optional)
