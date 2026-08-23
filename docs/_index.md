---
title: Microservice
weight: 1
---

## Introduction

Laravel package for building service meshes over HTTP and RabbitMQ. It provides ECDSA-signed inter-service HTTP communication with manifest-driven gateway routing, plus an event bus for asynchronous fan-out between services. The package is opt-in feature-by-feature — services that don't need the bus, the gateway, retries, or even idempotency pay nothing for those subsystems.

You may use the package to stitch together a small handful of services or a large cluster. The conventions are consistent across both surfaces: each service signs HTTP requests and event envelopes with its own key pair, certified by a cluster CA and verified by peers against that one constant, and the same `MessageHandler` contract drives every consumer.

## Requirements

- PHP 8.4 or higher
- Laravel 13.17 or higher
- Redis (for manifest storage, idempotency, and circuit breaker state)
- RabbitMQ (only if you use the event bus)

## How It Works

Each service exposes a manifest endpoint that lists its public routes and any metadata the gateway needs.

The gateway pulls these manifests on a schedule, stores them in cache, and registers proxy routes automatically. Every inter-service HTTP request — whether direct or via the gateway — is signed with the sender's own ECDSA (P-256) private key and verified at the destination against a certificate proving that key, issued by a cluster CA every service already trusts.

Outgoing requests can also retry transient failures, trip a circuit breaker against a service that's clearly down, and carry W3C trace context end-to-end — none of it required to get started, all of it available once you need it. Every service exposes health, readiness, and Prometheus metrics endpoints out of the box.

For asynchronous work, the message bus publishes signed envelopes to a topic exchange on RabbitMQ. Subscribers run a long-lived listener that consumes events, verifies signatures, and dispatches handlers either inline or through the Laravel Queue.

## Documentation

- [Installation](installation.md) — setup, environment configuration, and the full config reference
- [Client](client.md) — signed HTTP requests, retries, circuit breaker, tracing, JSON:API responses
- [Security](security.md) — the trust model, middleware, and idempotency
- [Gateway](gateway.md) — service discovery, route registration, request forwarding, health & metrics
- [Message Bus](message-bus.md) — publishing and consuming events over RabbitMQ
