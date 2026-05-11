---
title: Microservice
weight: 1
---

# jurager/microservice

A Laravel package for secure HTTP communication between microservices with manifest-driven gateway routing.

**Requirements:**

- PHP 8.2+
- Laravel 11+
- Redis
- Guzzle 7+

## How it works

Services expose a manifest endpoint. The gateway pulls manifests on a schedule, stores them in Redis, and registers proxy routes automatically. Every inter-service HTTP request is signed with HMAC-SHA256.

## Contents

- [Installation](installation.md) — setup and configuration
- [Client](client.md) — sending requests, JSON:API responses, parallel requests
- [Security](security.md) — middleware and idempotency
- [Gateway](gateway.md) — discovery, routing, health endpoint
