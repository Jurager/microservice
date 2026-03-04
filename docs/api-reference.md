---
title: Api Reference
weight: 90
---

# API Reference

## Client

`Jurager\Microservice\Client\ServiceClient`

- `service(string $name): PendingServiceRequest`
- `send(PendingServiceRequest $request): ServiceResponse`

`Jurager\Microservice\Client\PendingServiceRequest`

- `get(string $path): static`
- `post(string $path, ?array $body = null): static`
- `put(string $path, ?array $body = null): static`
- `patch(string $path, ?array $body = null): static`
- `delete(string $path): static`
- `withHeaders(array $headers): static`
- `withQuery(array $query): static`
- `withBody(array $body): static`
- `timeout(int $seconds): static`
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
- `throw(): static` — throws `ServiceRequestException` if `failed()`

## Gateway

`Jurager\Microservice\Gateway\Gateway`

- `routes(?Closure $overrides = null, ?array $services = null, ?string $controller = null): void`

`Jurager\Microservice\Gateway\GatewayRoutes`

- `service(string $name): static`
- `prefix(string $prefix): static`
- `middleware(array $middleware): static`
- `get(string $uri, array|Closure|null $action = null): static`
- `post(string $uri, array|Closure|null $action = null): static`
- `put(string $uri, array|Closure|null $action = null): static`
- `patch(string $uri, array|Closure|null $action = null): static`
- `delete(string $uri, array|Closure|null $action = null): static`

## Registry

`Jurager\Microservice\Registry\ManifestRegistry`

- `build(): array` — builds manifest from current service routes
- `store(array $manifest): void` — stores manifest in Redis with TTL

`Jurager\Microservice\Registry\RouteRegistry`

- `getAllManifests(): array` — returns all stored manifests from Redis
- `getAllRoutes(): array` — flattens routes across all manifests
- `resolve(string $method, string $uri): ?array` — finds the service for a given request

## Exceptions

| Exception | When |
| --- | --- |
| `ServiceRequestException` | `throw()` called on a failed response (4xx/5xx) |
| `ServiceUnavailableException` | service URL cannot be resolved or request fails |
| `MissingSignatureException` | `X-Signature` or `X-Timestamp` header is missing |
| `InvalidSignatureException` | signature mismatch or timestamp expired |
| `MissingServiceNameException` | `X-Service-Name` header is missing (TrustService) |
| `InvalidRequestIdException` | `X-Request-Id` is not a valid UUID v4 |
| `DuplicateRequestException` | duplicate in-flight idempotent request |

## HTTP Endpoints

| Method | URI | Middleware | Description |
| --- | --- | --- | --- |
| `GET` | `/microservice/manifest` | `TrustService` | Returns current service manifest (routes, base URL, timeout) |
| `GET` | `/microservice/health` | none | Gateway-only: sync status for all configured services (override via `SERVICE_HEALTH_ENDPOINT`) |

## Events

| Event | Dispatched when |
| --- | --- |
| `RoutesRegistered` | manifest endpoint called on a service |
| `ManifestReceived` | gateway successfully pulled and stored a manifest via `microservice:sync` |
| `IdempotentRequestDetected` | response served from idempotency cache |
