---
title: Microservice
weight: 1
---

# jurager/microservice

A Laravel package for secure HTTP communication between microservices with manifest-driven gateway routing.

It solves four core problems in service-to-service communication:

- **Trust** — every inter-service call is verified via HMAC-SHA256 signatures.
- **Discovery** — services expose their routes; gateways pull and proxy them automatically.
- **Idempotency** — safe retries for mutating requests via `X-Request-Id`.
- **JSON:API consumption** — typed `Item` objects and `CollectionDocument`/`ItemDocument` wrappers eliminate manual array parsing when services speak JSON:API.

**Requirements:** 

* PHP 8.2+ · 
* Laravel 11+
* Redis
* Guzzle 7+

## Architecture

```
Microservice (e.g. oms)              Gateway (e.g. gateway-admin)
────────────────────────             ──────────────────────────────
GET /microservice/manifest  ←──────  microservice:sync (scheduler)
                                              │
                                     stores manifest in Redis
                                              │
                                     Gateway::routes() registers proxy routes
```

No push, no shared registry. The gateway pulls; services just expose a manifest endpoint.

## Contents

- [Installation](installation.md) — Composer, environment, full config reference
- [Client](client.md) — Sending signed requests, JSON:API documents, passthrough responses
- [Security](security.md) — HMAC signing, middleware, and idempotency
- [Gateway](gateway.md) — Discovery, proxy routing, health endpoint, events
