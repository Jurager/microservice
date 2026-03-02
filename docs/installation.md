---
title: Installation
weight: 20
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

Minimum settings for any service (microservice or gateway):

```env
SERVICE_NAME=oms
SERVICE_SECRET=base64-generated-secret
SERVICE_REDIS_CONNECTION=default
```

Additional settings for a microservice:

```env
APP_URL=http://oms:8000
SERVICE_TIMEOUT=5
```

Additional settings for a gateway:

```env
SERVICE_NAME=gateway-admin
SERVICE_DISCOVERY_PATTERN=http://{service}:8000
SERVICE_HEALTH_ENDPOINT=/microservice/health
```

Generate a shared secret:

```bash
openssl rand -base64 32
```

> [!WARNING]
> All services in the cluster must use the same `SERVICE_SECRET`. If they differ, HMAC verification will fail.

> [!NOTE]
> Each service uses its own Redis instance. Gateways use their own separate Redis instance shared across gateway pods.
