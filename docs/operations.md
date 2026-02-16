---
title: Operations
weight: 70
---

# Operations

## Health Tracking

`HealthRegistry` stores failure state per service instance in Redis.

- An instance becomes unhealthy after `failure_threshold` failures.
- After `recovery_timeout`, the instance is retried.
- A successful request clears the failure state.

Health endpoint (optional):

- Set `SERVICE_HEALTH_ENDPOINT` to expose a route that returns current health.

> [!NOTE]
> The health endpoint is public by default. Protect it at the route level if needed.

## Events

| Event | Trigger |
| --- | --- |
| `ServiceRequestFailed` | each failed attempt before failover |
| `ServiceBecameUnavailable` | all instances exhausted |
| `ServiceHealthChanged` | health state changed |
| `HealthCheckFailed` | failure counter increased |
| `RoutesRegistered` | service manifest registered |
| `ManifestReceived` | gateway accepted manifest |
| `IdempotentRequestDetected` | response served from idempotency cache |

## Artisan Commands

| Command | Description |
| --- | --- |
| `microservice:register` | build and register the service manifest |
| `microservice:health` | show instance health state |

## Redis Keys

All keys use the prefix `microservice.redis.prefix` (default `microservice:`).

| Key Pattern | Purpose | TTL |
| --- | --- | --- |
| `{prefix}health:{service}:{md5(url)}` | instance health state | `recovery_timeout * 2` |
| `{prefix}manifest:{service}` | service manifest JSON | `manifest.ttl` |
| `{prefix}idempotency:{request_id}` | cached response | `idempotency.ttl` |
| `{prefix}idempotency:{request_id}:lock` | in-flight lock | `idempotency.lock_timeout` |
