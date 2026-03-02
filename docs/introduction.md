---
title: Introduction
weight: 10
---

# Introduction

Jurager/Microservice is a Laravel package for secure HTTP communication between microservices with manifest-driven gateway routing.

It solves three core problems in service-to-service communication:

- **Trust**: verify who is calling via HMAC signatures.
- **Discovery**: services expose their routes; gateways pull and proxy them automatically.
- **Idempotency**: safe retries for non-safe requests via `X-Request-Id`.

## Core Ideas

- **Signed requests** — every inter-service call includes `X-Signature`, `X-Timestamp`, and `X-Service-Name`.
- **Pull-based manifest sync** — gateways periodically pull route manifests from services and build proxy routes dynamically. No push, no shared registry.
- **DNS-aware discovery** — service URLs are resolved via a configurable pattern, ready for Docker Compose today and Kubernetes tomorrow.
- **Idempotency** — duplicate POST/PUT/PATCH/DELETE requests are deduplicated via `X-Request-Id`.

## Architecture Overview

```
Microservice (e.g. oms)             Gateway (e.g. gateway-admin)
─────────────────────────           ─────────────────────────────
GET /microservice/manifest   ←───   microservice:sync (scheduler)
                                           │
                                    stores in local Redis
                                           │
                                    Gateway::routes() reads Redis
                                    and registers proxy routes
```

Retries, failover, and instance health tracking are intentionally delegated to the infrastructure layer (Kubernetes, load balancer, service mesh).

## When To Use

- You run multiple Laravel services that call each other.
- You need a gateway that discovers and proxies service routes without manual route configuration.
- You want consistent HMAC signing and idempotency across all internal traffic.

## Requirements

- PHP 8.2+
- Laravel 11+ (Laravel 12 supported)
- Redis
- Guzzle 7+

> [!NOTE]
> Redis is required per service. Manifests and idempotency state are stored there. Gateways use their own Redis instance.
