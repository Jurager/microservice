# Introduction

Jurager/Microservice is a Laravel package for secure, resilient HTTP communication between microservices.

It solves three common problems in service-to-service communication:

- **Trust**: verify who is calling via HMAC signatures.
- **Reliability**: retries, failover, and health tracking across instances.
- **Discovery**: a manifest-driven gateway that can proxy routes without manual config.

## Core Ideas

- **Signed requests** using HMAC headers.
- **Retries and failover** across multiple service instances.
- **Health tracking** to avoid unhealthy nodes.
- **Route discovery** for gateway proxying.
- **Idempotency** for non-safe requests.

## When To Use

- You run multiple Laravel services that call each other.
- You need consistent signing and retry behavior.
- You want a gateway that discovers service routes.

## Requirements

- PHP 8.2+
- Laravel 11+ (Laravel 12 supported)
- Redis
- Guzzle 7+

> [!NOTE]
> Redis is required. Manifests, health state, and idempotency are stored there.
