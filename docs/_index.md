---
title: Microservice
weight: 1
---

## Introduction

Laravel package for building service meshes over HTTP and RabbitMQ. It provides HMAC-signed inter-service HTTP communication with manifest-driven gateway routing, plus an event bus for asynchronous fan-out between services. The package is opt-in feature-by-feature — services that don't need the bus, the gateway, or even idempotency pay nothing for those subsystems.

You may use the package to stitch together a small handful of services or a large cluster. The conventions are consistent across both surfaces: the same shared secret signs HTTP requests and event envelopes, and the same `MessageHandler` contract drives every consumer.

## Requirements

- PHP 8.4 or higher
- Laravel 11, 12, or 13
- Redis (for manifest storage and idempotency cache)
- RabbitMQ (only if you use the event bus)

## How It Works

Each service exposes a manifest endpoint that lists its public routes, the secret-signed transport requirements, and any metadata the gateway needs.

The gateway pulls these manifests on a schedule, stores them in Redis, and registers proxy routes automatically. Every inter-service HTTP request — whether direct or via the gateway — is signed with HMAC-SHA256 and verified at the destination.

For asynchronous work, the message bus publishes signed envelopes to a topic exchange on RabbitMQ. Subscribers run a long-lived listener that consumes events, verifies signatures, and dispatches handlers either inline or through the Laravel Queue.

## Documentation

- [Installation](installation.md) — setup and environment configuration
- [Client](client.md) — sending HMAC-signed HTTP requests, JSON:API responses, parallel requests
- [Security](security.md) — middleware, exceptions, idempotency
- [Gateway](gateway.md) — service discovery, route registration, health endpoint
- [Message Bus](message-bus.md) — publishing and consuming events over RabbitMQ
