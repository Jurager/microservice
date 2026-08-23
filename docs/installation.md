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

This creates `config/microservice.php`, which you may freely edit. Every option is documented inline and driven by an environment variable, so for a standard setup you typically won't need to touch the file at all — just set the variables described below.

### Cache Requirements

The package relies on Laravel's Cache for storing route manifests, idempotency keys, and circuit breaker state. Since microservices typically run across multiple servers or containers, your configured cache driver must be distributed and support **atomic locks** rather than living in a single process's memory.

The package enforces this by checking your driver against a whitelist at boot:

- `redis` (recommended)
- `memcached`
- `dynamodb`
- `database`

If you run the package with an unsupported driver — `file`, `array`, or `apc` — it throws a `RuntimeException` as soon as the application boots, rather than failing unpredictably the first time a lock is needed. The `array` driver is the one exception, and only in the `testing` environment, where a single in-process cache is exactly what you want.

## Environment Configuration

### Required Variables

Every service in the cluster must define its name, its own signing key pair, and the cluster CA's public key in its `.env` file:

```env
SERVICE_NAME=oms
SERVICE_PRIVATE_KEY=base64-generated-private-key
SERVICE_CERTIFICATE=base64-issued-certificate
SERVICE_CA_PUBLIC_KEY=base64-cluster-ca-public-key
```

`SERVICE_NAME` is a unique, stable identifier such as `oms`, `pim`, or `sfm` — it appears in signatures, manifest registration, and event envelopes, so changing it later effectively makes the service look new to every peer. `SERVICE_PRIVATE_KEY` is this service's own ECDSA (P-256) private key, used to sign all outgoing traffic; it is never given to any other service, so compromising it only lets an attacker forge traffic as that one service. `SERVICE_CERTIFICATE` and `SERVICE_CA_PUBLIC_KEY` are how peers verify that traffic — see [Certificates and the Cluster CA](security.md#certificates-and-the-cluster-ca) for how the two fit together.

> [!WARNING]
> Never set `SERVICE_DEBUG=true` in production. Debug mode disables signature verification across HTTP middleware and the message bus, and skips the signing-config validation that would otherwise catch a missing key at boot.

### Generating a Key Pair

Every service needs its own key pair, and a certificate binding it to that service's name, issued by the cluster's CA. The CA itself only needs to exist once per cluster:

```bash
# Once, wherever certificates get issued — never on a running service:
php artisan microservice:authority:generate
```

This prints the CA's key pair. The public key goes into every service's `.env` as `SERVICE_CA_PUBLIC_KEY`, identically — it's the one constant every service shares.

> [!IMPORTANT]
> Keep the CA private key off the running infrastructure entirely — a workstation, a vault, a one-off script. It's only needed to issue certificates, never at request time, so there's no reason for any deployed service to ever hold it.

Each service then generates its own pair and gets it certified:

```bash
# On the service itself:
php artisan microservice:keygen

# Wherever the CA private key lives, using the public key microservice:keygen printed:
php artisan microservice:certificate:issue oms base64-oms-public-key
```

`microservice:keygen` writes `SERVICE_PRIVATE_KEY` to `.env` directly and prints the public key alongside it, so you can hand that public key to whoever holds the CA key without ever exposing the private one. `microservice:certificate:issue` prints the resulting certificate — not a secret itself — which goes into that same service's `.env` as `SERVICE_CERTIFICATE`.

Rotating a compromised key is a single-service operation, and this is really the point of the whole scheme: run `microservice:keygen` again, get a new certificate issued for the new public key, and deploy that one service. No other service's configuration changes, because verification only ever depends on the constant `SERVICE_CA_PUBLIC_KEY` — never on a list of individual peer keys that would need updating everywhere.

### Gateway Configuration

Services that act as a [gateway](gateway.md) need two additional variables:

```env
SERVICE_DISCOVERY_PATTERN=http://{service}:8000
SERVICE_MANIFEST_SERVICES=oms,pim,sfm
```

`SERVICE_DISCOVERY_PATTERN` is a URL template in which the `{service}` placeholder is replaced with each service name during discovery. The pattern above suits Docker Compose; for Kubernetes you'd typically use something like `http://{service}.default.svc.cluster.local`.

`SERVICE_MANIFEST_SERVICES` is a comma-separated list of the services the gateway should sync manifests for. See the [Gateway documentation](gateway.md) for the full picture on routing and discovery. The gateway itself needs a key pair and certificate, generated and issued exactly the same way as any other service — see [Certificates and the Cluster CA](security.md#certificates-and-the-cluster-ca).

## Configuration Reference

The following environment variables control package behavior. All of them have sensible defaults; only the four in [Required Variables](#required-variables) must be set explicitly outside debug mode.

#### Core

| Variable | Default | Description |
|---|---|---|
| `SERVICE_NAME` | `app` | Unique service identifier |
| `SERVICE_VERSION` | — | Optional build/release identifier, surfaced in the health report |
| `SERVICE_DEBUG` | `false` | Disables signature verification and its boot-time validation — local development only |
| `SERVICE_TIMEOUT` | `30` | HTTP timeout (seconds) published in this service's own manifest |
| `SERVICE_CONNECT_TIMEOUT` | `5` | Max seconds to wait while establishing a TCP connection to a peer |

#### Signing

| Variable | Default | Description |
|---|---|---|
| `SERVICE_PRIVATE_KEY` | — | This service's own ECDSA (P-256) private key (required outside debug mode) |
| `SERVICE_CERTIFICATE` | — | This service's certificate, binding its name to its public key (required outside debug mode) |
| `SERVICE_CA_PUBLIC_KEY` | — | The cluster CA's public key, identical on every service (required outside debug mode) |

#### Gateway & Manifests

| Variable | Default | Description |
|---|---|---|
| `SERVICE_DISCOVERY_PATTERN` | — | URL pattern for service discovery (`{service}` is replaced) |
| `SERVICE_MANIFEST_SERVICES` | — | Comma-separated list of services the gateway should sync |
| `SERVICE_MANIFEST_SYNC_INTERVAL` | `5` | Auto-sync interval in minutes (`0` disables the scheduler) |
| `SERVICE_MANIFEST_TTL` | `0` | How long a synced manifest lives in cache, in seconds (`0` = until replaced) |
| `SERVICE_MANIFEST_PREFIX` | `api` | Only routes under this URI prefix are published in this service's manifest |

#### Idempotency

| Variable | Default | Description |
|---|---|---|
| `SERVICE_IDEMPOTENCY_TTL` | `86400` | How long a cached idempotent response is kept, in seconds |
| `SERVICE_IDEMPOTENCY_MAX_BODY_SIZE` | `1048576` | Largest response body worth caching for replay, in bytes (`0` disables the limit) |

#### Retries & Circuit Breaker

See [Retries](client.md#retries) and [Circuit Breaker](client.md#circuit-breaker) for how these interact.

| Variable | Default | Description |
|---|---|---|
| `SERVICE_RETRIES_MAX` | `0` | Retry attempts for connection failures and `5xx` responses (`0` disables retrying) |
| `SERVICE_RETRIES_DELAY` | `100` | Initial retry delay, in milliseconds |
| `SERVICE_RETRIES_MULTIPLIER` | `2.0` | Exponential backoff multiplier applied to each subsequent retry |
| `SERVICE_CIRCUIT_BREAKER_THRESHOLD` | `0` | Consecutive failures before the breaker opens (`0` disables it) |
| `SERVICE_CIRCUIT_BREAKER_TIMEOUT` | `30` | Seconds the breaker stays open before a probe request is allowed |
| `SERVICE_CIRCUIT_BREAKER_WINDOW` | `60` | Seconds a failure streak is remembered before resetting |

#### Tracing

| Variable | Default | Description |
|---|---|---|
| `SERVICE_TRACING_ENABLED` | `true` | Propagate W3C Trace Context (`traceparent`/`tracestate`) on outgoing requests |

#### Health & Metrics

See [Health & Observability](gateway.md#health--observability) for the full response shapes.

| Variable | Default | Description |
|---|---|---|
| `SERVICE_HEALTH_ENDPOINT` | `/microservice/health` | Detailed health report (empty value disables it) |
| `SERVICE_HEALTH_LIVENESS_ENDPOINT` | `/microservice/health/live` | Liveness probe |
| `SERVICE_HEALTH_READINESS_ENDPOINT` | `/microservice/health/ready` | Readiness probe |
| `SERVICE_HEALTH_METRICS_ENDPOINT` | `/microservice/metrics` | Prometheus text exposition of the health report |
| `SERVICE_HEALTH_VERBOSE` | `false` | Include infrastructure details (base URLs, timeouts) in the detailed report by default |
| `SERVICE_HEALTH_CACHE_TTL` | `15` | Seconds the detailed report (and `/metrics`) is cached — the checks it runs open a RabbitMQ connection |

#### Message Bus

See [Message Bus](message-bus.md) for the full configuration, including RabbitMQ connection settings and dead-letter routing.

| Variable | Default | Description |
|---|---|---|
| `MESSAGE_BUS_ENABLED` | `true` | Disables publishing and the listener entirely when `false` |
| `MESSAGE_BUS_EXCHANGE` | `events` | Topic exchange used for event routing |
