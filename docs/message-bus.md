---
title: Message Bus
weight: 50
---

## Introduction

The Message Bus provides event-based communication between microservices using RabbitMQ.

Events are published to a topic exchange and consumed by services through message handlers.

The Message Bus supports:

- event publishing and consumption;
- automatic handler discovery;
- queued and synchronous handlers;
- dynamic event subscriptions;
- message signing;
- dead-letter queues;
- connection recovery;
- graceful shutdown.

The Message Bus is opt-in. Services that do not use it do not open a RabbitMQ connection.

## Configuration

The Message Bus is configured in `config/microservice.php` under the `bus` key.

The following environment variables are available:

```env
MESSAGE_BUS_ENABLED=true
MESSAGE_BUS_EXCHANGE=events
MESSAGE_BUS_CONFIRM_TIMEOUT=5
MESSAGE_BUS_MAX_IDLE=60
MESSAGE_BUS_PUBLISH_ATTEMPTS=2

RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_HEARTBEAT=60
RABBITMQ_TIMEOUT=10

MESSAGE_BUS_DLQ_ENABLED=true
MESSAGE_BUS_DLQ_EXCHANGE=events.dlx
```

### Message Bus

| Variable | Default | Description |
| --- | --- | --- |
| `MESSAGE_BUS_ENABLED` | `true` | Enables the Message Bus. |
| `MESSAGE_BUS_EXCHANGE` | `events` | RabbitMQ topic exchange. |
| `MESSAGE_BUS_CONFIRM_TIMEOUT` | `5` | Broker confirmation timeout in seconds. |
| `MESSAGE_BUS_MAX_IDLE` | `60` | Maximum connection idle time in seconds. |
| `MESSAGE_BUS_PUBLISH_ATTEMPTS` | `2` | Maximum publish attempts after a connection failure. |

### RabbitMQ

| Variable | Default | Description |
| --- | --- | --- |
| `RABBITMQ_HOST` | `127.0.0.1` | RabbitMQ host. |
| `RABBITMQ_PORT` | `5672` | RabbitMQ port. |
| `RABBITMQ_USER` | `guest` | RabbitMQ username. |
| `RABBITMQ_PASSWORD` | `guest` | RabbitMQ password. |
| `RABBITMQ_VHOST` | `/` | RabbitMQ virtual host. |
| `RABBITMQ_HEARTBEAT` | `60` | AMQP heartbeat interval. |
| `RABBITMQ_TIMEOUT` | `10` | Connection and I/O timeout. |

### Dead-Letter Queues

| Variable | Default | Description |
| --- | --- | --- |
| `MESSAGE_BUS_DLQ_ENABLED` | `true` | Enables dead-letter routing. |
| `MESSAGE_BUS_DLQ_EXCHANGE` | `events.dlx` | Dead-letter exchange. |

### Disabling the Message Bus

Set `MESSAGE_BUS_ENABLED` to `false`:

```env
MESSAGE_BUS_ENABLED=false
```

When disabled, `MessageBus::publish()` returns without opening an AMQP connection.

The `microservice:listen` command cannot be started while the Message Bus is disabled.

## Publishing Events

Inject `MessageBus` and call `publish()`:

```php
use Jurager\Microservice\Bus\MessageBus;

class BroadcastSiteEvents
{
    public function __construct(
        private MessageBus $bus,
    ) {}

    public function handleSiteUpdated(SiteUpdated $event): void
    {
        $this->bus->publish(
            type: 'sfm.site.updated',
            payload: [
                'site_id' => $event->siteId,
                'domain' => $event->domain,
            ],
        );
    }
}
```

### Event Types

Event types use the following format:

```text
{service}.{entity}.{event}
```

For example:

```text
sfm.site.updated
oms.order.shipped
```

The event type is used as the RabbitMQ routing key.

Because the exchange is a topic exchange, consumers can subscribe to a specific event or a pattern:

```text
sfm.site.*
```

### Delivery

The publisher uses RabbitMQ publisher confirms and the `mandatory` flag.

If the broker cannot route an event to any queue, `publish()` throws a `RuntimeException`.

```php
try {
    $this->bus->publish(
        'sfm.site.updated',
        ['site_id' => $event->siteId],
    );
} catch (Throwable $e) {
    // The event was not delivered.
}
```

Connection failures are retried according to `MESSAGE_BUS_PUBLISH_ATTEMPTS`.

Unroutable events are not retried because the connection is healthy and no matching queue exists.

> [!NOTE]
> If publishing an event is part of a state-changing operation, consider publishing it within the same database transaction.

## Event Envelope

Every event is published as an envelope containing metadata and the domain payload:

```json
{
    "type": "sfm.site.updated",
    "service": "sfm",
    "occurred_at": "2026-05-21T10:30:00+00:00",
    "request_id": "req_abc123",
    "payload": {
        "site_id": 1,
        "domain": "example.com"
    },
    "signature": "9f8a4b…",
    "certificate": "eyJzZXJ2aWNlIjoic2ZtIiw…"
}
```

| Field | Description |
| --- | --- |
| `type` | Event type. |
| `service` | Service that published the event. |
| `occurred_at` | Publication time in ISO 8601 format. |
| `request_id` | Request ID from `X-Request-Id`, when available. |
| `payload` | Domain event payload. |
| `signature` | ECDSA signature. |
| `certificate` | Publisher certificate. |

The `request_id` is propagated from the incoming HTTP request so events can be traced back to the originating request.

### Signing

Each envelope is signed with the publisher's private key.

Before dispatching an event, the consumer verifies:

- the publisher certificate;
- the trusted cluster CA;
- the service name;
- the envelope signature.

Invalid envelopes are rejected and sent to the dead-letter queue.

> [!WARNING]
> Signature verification is disabled when `microservice.debug=true`.
>
> Never enable debug mode in production.

## Consuming Events

### Message Handlers

A message handler implements the `MessageHandler` contract:

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Microservice\Bus\Contracts\MessageHandler;

class ForgetSiteConfig implements MessageHandler, ShouldQueue
{
    use Queueable;

    public static function type(): string
    {
        return 'sfm.site.config';
    }

    public function __construct(
        public readonly int $siteId,
    ) {}

    public static function from(
        array $payload,
        string $type = '',
    ): static {
        return new static($payload['site_id']);
    }

    public function handle(): void
    {
        Cache::forget("site:config:{$this->siteId}");
    }
}
```

The handler defines:

- the event type through `type()`;
- how the handler is created through `from()`;
- the event processing logic through `handle()`.

### Queued Handlers

Implement `ShouldQueue` to dispatch the handler to Laravel's queue:

```php
class ForgetSiteConfig implements MessageHandler, ShouldQueue
{
    use Queueable;

    // ...
}
```

Queued handlers use the standard Laravel queue features, including retries, backoff, and `failed_jobs`.

### Synchronous Handlers

Handlers without `ShouldQueue` run synchronously inside the listener.

Use synchronous handlers for small operations that can safely run in the listener process.

If a synchronous handler throws, the message is considered failed and can be sent to the DLQ.

## Handler Discovery

Handlers are discovered automatically when the listener starts.

The package scans for concrete classes implementing `MessageHandler` and creates consumers for their event types.

No manual registration is required.

If you need to customize discovery, extend:

```text
Jurager\Microservice\Bus\HandlerDiscovery
```

and override its singleton binding.

## Dynamic Event Types

`type()` may return event types dynamically.

This allows a handler to determine its subscriptions at runtime, for example from a database.

Use `--rescan` to detect changes without restarting the listener:

```bash
php artisan microservice:listen --rescan=30
```

The value is the rescan interval in seconds.

Set it to `0` to disable rescanning:

```bash
php artisan microservice:listen --rescan=0
```

A rescan re-evaluates `type()` on already discovered handlers. It does not repeat filesystem discovery.

When a type is removed, its consumer is stopped but the queue remains available. Events published while the type is inactive remain in the queue and are consumed when the type becomes active again.

## Running the Listener

Start the listener with:

```bash
php artisan microservice:listen
```

For each discovered event type, the listener creates a durable queue using:

```text
{service}.{type}
```

For example:

```text
api.sfm.site.config
```

### Worker Limits

Limit the listener by memory usage or number of processed messages:

```bash
php artisan microservice:listen \
    --memory=256 \
    --max-jobs=1000
```

The listener exits when either limit is reached.

Using `--max-jobs` with a process supervisor allows the worker to restart periodically.

### Reconnecting

The listener automatically reconnects when the RabbitMQ connection is lost.

Configure reconnect attempts and the maximum backoff:

```bash
php artisan microservice:listen \
    --max-reconnects=10 \
    --backoff=30
```

Reconnect delays use exponential backoff:

```text
1s → 2s → 4s → 8s → ...
```

The delay is capped by `--backoff`.

Set `--max-reconnects=0` to retry indefinitely.

Messages are acknowledged only after successful processing. If the connection is lost before acknowledgement, RabbitMQ requeues the message.

### Liveness

Use `--heartbeat` to expose listener liveness through a file:

```bash
php artisan microservice:listen \
    --heartbeat=/tmp/listener.heartbeat
```

The file is updated while the listener is running and during reconnect attempts.

A Kubernetes liveness probe can check its age:

```yaml
livenessProbe:
    exec:
        command:
            - /bin/sh
            - -c
            - test $(( $(date +%s) - $(stat -c %Y /tmp/listener.heartbeat) )) -lt 300
```

> [!TIP]
> Set the threshold higher than the maximum expected duration of synchronous handlers.

## Dead-Letter Queues

Dead-letter queues are enabled by default.

Each handler has a main queue and a corresponding DLQ:

```text
api.sfm.site.config
api.sfm.site.config.dlq
```

The main queue uses `MESSAGE_BUS_EXCHANGE`.

The DLQ uses `MESSAGE_BUS_DLQ_EXCHANGE`.

### Failed Messages

Messages are sent to the DLQ when:

- the signature or certificate is invalid;
- the certificate does not match the claimed service;
- the message body is invalid JSON;
- the envelope is not a JSON object;
- a synchronous handler throws.

Queued handler failures are handled by Laravel's queue system instead of the DLQ.

### Inspecting the DLQ

Use the RabbitMQ Management UI or `rabbitmqadmin`:

```bash
rabbitmqadmin get \
    queue=api.sfm.site.config.dlq \
    count=10
```

### Disabling the DLQ

Set:

```env
MESSAGE_BUS_DLQ_ENABLED=false
```

Failed messages are acknowledged and discarded when the DLQ is disabled.

> [!WARNING]
> Changing the DLQ configuration for an existing service requires deleting the affected main queues first.
>
> RabbitMQ does not allow an existing queue to be redeclared with a different `x-dead-letter-exchange` argument.

## Graceful Shutdown

The listener handles `SIGTERM` and `SIGINT`.

On shutdown, it:

1. finishes the current message;
2. closes the AMQP channel;
3. exits successfully.

This allows process supervisors and Kubernetes to stop the listener without interrupting the current message.

Shutdown signals are also handled while the listener is reconnecting.

## Diagnostic Commands

### List Registered Events

Use:

```bash
php artisan microservice:events
```

The command displays each discovered event type, queue, handler, and execution mode:

```text
+------------------+----------------------+----------------------------------+--------+
| Type             | Queue                | Handler                          | Mode   |
+------------------+----------------------+----------------------------------+--------+
| sfm.site.updated | api.sfm.site.updated | App\Jobs\Cache\ForgetSiteDomain  | queued |
| sfm.site.config  | api.sfm.site.config  | App\Jobs\Cache\ForgetSiteConfig | queued |
| sfm.site.regions | api.sfm.site.regions | App\Jobs\Cache\ForgetSiteRegions| queued |
+------------------+----------------------+----------------------------------+--------+
```

Use this command to verify handler discovery and queue configuration.

### Emit an Event

Use `microservice:emit` to publish an event manually:

```bash
php artisan microservice:emit \
    sfm.site.config \
    '{"site_id":1}'
```

The command uses the same publishing pipeline as application code, including envelope creation and signing.

> [!TIP]
> Use `microservice:emit` to verify the event pipeline after a deployment.

## Production Deployment

The listener is a long-running process and should be managed by a process supervisor such as Supervisor, systemd, or Kubernetes.

### Supervisor

Example configuration:

```ini
[program:api-microservice-listener]
command=php /var/www/api/artisan microservice:listen --memory=256 --max-jobs=1000 --heartbeat=/tmp/listener.heartbeat
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=30
```

The listener handles `SIGTERM` gracefully, so Supervisor can restart it during deployments without interrupting the current message.

> [!TIP]
> Monitor DLQ depth in production. A growing DLQ usually indicates invalid messages, configuration problems, or failing handlers.