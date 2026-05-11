---
title: Installation
weight: 10
---

# Installation

```bash
composer require jurager/microservice
php artisan vendor:publish --tag=microservice-config
```

## Environment

Required on every service:

```env
SERVICE_NAME=oms
SERVICE_SECRET=base64-generated-secret
```

Generate a shared secret:

```bash
openssl rand -base64 32
```

> [!WARNING]
> All services in the cluster must share the same `SERVICE_SECRET`.

> [!WARNING]
> Never set `SERVICE_DEBUG=true` in production — it disables signature verification.

Additional variables for a **gateway**:

```env
SERVICE_DISCOVERY_PATTERN=http://{service}:8000   # Docker / Kubernetes
SERVICE_MANIFEST_SERVICES=oms,pim,sfm
```

## Key Configuration Options

| Env | Default | Description |
|---|---|---|
| `SERVICE_NAME` | `app` | Unique service identifier |
| `SERVICE_SECRET` | — | Shared HMAC secret |
| `SERVICE_DEBUG` | `false` | Disables signature verification — local dev only |
| `SERVICE_REDIS_CONNECTION` | `default` | Redis connection name |
| `SERVICE_TIMEOUT` | `30` | HTTP timeout published in the manifest (seconds) |
| `SERVICE_DISCOVERY_PATTERN` | — | Gateway: URL pattern for service discovery (`{service}` is replaced) |
| `SERVICE_MANIFEST_SERVICES` | — | Gateway: comma-separated list of services to sync |
| `SERVICE_MANIFEST_SYNC_INTERVAL` | `5` | Gateway: auto-sync interval in minutes (`0` disables) |
| `SERVICE_IDEMPOTENCY_TTL` | `86400` | How long idempotent responses are cached (seconds) |
