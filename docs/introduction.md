# Introduction

Jurager/Microservice is a Laravel package for secure, resilient HTTP communication between microservices.

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
