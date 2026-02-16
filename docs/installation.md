---
title: Installation
---

# Installation

Install the package with Composer and publish the configuration.

## Install

```bash
composer require jurager/microservice
```

## Publish Config

```bash
php artisan vendor:publish --tag=microservice-config
```

## Environment Variables

Minimum settings for a service:

```env
SERVICE_NAME=oms
SERVICE_SECRET=base64-generated-secret
SERVICE_REDIS_CONNECTION=default
```

Generate a secret:

```bash
openssl rand -base64 32
```

> [!WARNING]
> All services in the cluster must use the same `SERVICE_SECRET`. If they differ, signatures will fail.

> [!NOTE]
> Redis is required. Manifests, health state, and idempotency are stored there.
