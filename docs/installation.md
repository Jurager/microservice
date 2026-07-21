---
title: Installation
weight: 10
---

## Installing the Package

You may install the package via Composer:

```bash
composer require jurager/microservice
```

After installing, publish the configuration file so you may tune it to your environment:

```bash
php artisan vendor:publish --tag=microservice-config
```

This creates `config/microservice.php`, which you may freely edit.

All options have sensible defaults driven by environment variables, so you typically won't need to touch the file for a standard setup.

### Cache Requirements

The package relies on Laravel's Cache for storing route manifests, idempotency keys, and circuit breaker state. Since microservices typically run across multiple servers or containers, your configured cache driver must be distributed and support **atomic locks**.

The package strictly enforces a whitelist of supported cache drivers:
- `redis` (recommended)
- `memcached`
- `dynamodb`
- `database`

If you attempt to run the package with an unsupported driver (such as `file`, `array`, or `apc`), it will throw a `RuntimeException` during application boot.

The `array` driver is only permitted in the `testing` environment.

## Environment Configuration

### Required Variables

Every service in the cluster must define two variables in its `.env` file:

```env
SERVICE_NAME=oms
SERVICE_SECRET=base64-generated-secret
```

The `SERVICE_NAME` is a unique identifier for the service. It appears in HMAC signatures, manifest registration, and event envelopes — choose a short, stable name such as `oms`, `pim`, or `sfm`. The `SERVICE_SECRET` is the shared HMAC key used to sign all inter-service traffic.

**All services in the cluster must share the same `SERVICE_SECRET`.** A mismatch causes every signed request to be rejected with `401`.

**Never set `SERVICE_DEBUG=true` in production.** Debug mode disables signature verification across HTTP middleware and the message bus, which is convenient for local development but unsafe anywhere else.

### Generating the Shared Secret

To generate a fresh secret, you may use `openssl`:

```bash
openssl rand -base64 32
```

Distribute the output to every service via your secret manager. Rotation is straightforward — deploy the new secret to every service simultaneously, then restart.

### Gateway Configuration

Services that act as a gateway need two additional variables:

```env
SERVICE_DISCOVERY_PATTERN=http://{service}:8000
SERVICE_MANIFEST_SERVICES=oms,pim,sfm
```

The `SERVICE_DISCOVERY_PATTERN` is a URL template — the `{service}` placeholder is replaced with each service name during discovery. The pattern shown above suits Docker Compose; for Kubernetes you may use something like `http://{service}.default.svc.cluster.local`.

`SERVICE_MANIFEST_SERVICES` is a comma-separated list of services the gateway should sync manifests for. See the [Gateway documentation](gateway.md) for full details on routing and discovery.

## Configuration Reference

The following environment variables control package behavior. All have defaults — only `SERVICE_NAME` and `SERVICE_SECRET` are required:

| Variable | Default | Description |
|---|---|---|
| `SERVICE_NAME` | `app` | Unique service identifier |
| `SERVICE_SECRET` | — | Shared HMAC secret (required outside debug mode) |
| `SERVICE_DEBUG` | `false` | Disables signature verification — local development only |
| `SERVICE_TIMEOUT` | `30` | HTTP timeout (seconds) published in the manifest |
| `SERVICE_DISCOVERY_PATTERN` | — | Gateway: URL pattern for service discovery (`{service}` is replaced) |
| `SERVICE_MANIFEST_SERVICES` | — | Gateway: comma-separated list of services to sync |
| `SERVICE_MANIFEST_SYNC_INTERVAL` | `5` | Gateway: auto-sync interval in minutes (`0` disables) |
| `SERVICE_IDEMPOTENCY_TTL` | `86400` | How long idempotent responses are cached (seconds) |
