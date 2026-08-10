---
title: Message Bus
weight: 50
---

## Introduction

The package provides an inter-service event bus over RabbitMQ, built directly on [php-amqplib/php-amqplib](https://github.com/php-amqplib/php-amqplib). You may use it to broadcast domain events between services without coupling them through HTTP calls. The bus ships its own publisher, consumer, HMAC envelope signing, and dead-letter routing — no higher-level transport wrappers are required.

The bus is opt-in per service. Services that don't publish or consume events pay nothing at runtime; the bus simply isn't activated.

## Configuration

The bus is configured in `config/microservice.php` under the `bus` key. The defaults are sensible for local development and may be overridden via environment variables:

```env
MESSAGE_BUS_ENABLED=true
MESSAGE_BUS_EXCHANGE=events

RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/

MESSAGE_BUS_DLQ_ENABLED=true
MESSAGE_BUS_DLQ_EXCHANGE=events.dlx
```

All events are published to a single topic exchange (`events` by default), routed by the event type as the routing key. This allows multiple services to subscribe to the same event independently.

### Disabling the Message Bus

For services that don't need to participate in inter-service messaging, you may disable the bus entirely:

```env
MESSAGE_BUS_ENABLED=false
```

With the bus disabled, `MessageBus::publish` logs a debug line and returns immediately — no AMQP connection is attempted. The `microservice:listen` command refuses to start. The package imposes no other runtime cost.

## Publishing Events

To publish an event, you may inject the `MessageBus` and call the `publish` method:

```php
use Jurager\Microservice\Bus\MessageBus;

class BroadcastSiteEvents
{
    public function __construct(private MessageBus $bus) {}

    public function handleSiteUpdated(SiteUpdated $event): void
    {
        $this->bus->publish(
            type: 'sfm.site.updated',
            payload: ['site_id' => $event->siteId, 'domain' => $event->domain],
        );
    }
}
```

The `MessageBus` is registered as a singleton. The AMQP connection is opened lazily on the first publish and reused for the lifetime of the process. Publishing failures are caught and logged — they never throw.

Event types follow the convention `{source_service}.{entity}.{event}`, for example `sfm.site.updated` or `oms.order.shipped`. The type becomes the AMQP routing key, so subscribing services can bind queues to specific events or wildcard patterns such as `sfm.site.*`.

### The Envelope

Every published event is wrapped in a standard envelope containing metadata and the domain payload:

```json
{
  "type": "sfm.site.updated",
  "service": "sfm",
  "occurred_at": "2026-05-21T10:30:00+00:00",
  "request_id": "req_abc123",
  "payload": {"site_id": 1, "domain": "example.com"},
  "signature": "9f8a4b…"
}
```

The `service` field is taken from `microservice.name`, `occurred_at` is the publish timestamp in ISO 8601, and `request_id` is propagated from the inbound HTTP request's `X-Request-Id` header (when available) to enable distributed tracing across event chains.

### Signing Envelopes

Every envelope is HMAC-signed with `microservice.secret` before publishing. The consumer verifies the signature before invoking the handler — envelopes with missing or invalid signatures are rejected and routed to the dead-letter queue.

This protects consumers from messages forged by anything with broker access but without the cluster secret. Signature verification is skipped when `microservice.debug=true`, mirroring the behavior of the HTTP `TrustGateway` middleware.

## Consuming Events

### Defining Message Handlers

A handler is a class that implements the `MessageHandler` contract. It declares the event type it processes and knows how to reconstruct itself from a domain payload:

```php
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ForgetSiteConfig implements MessageHandler, ShouldQueue
{
    use Queueable;

    public static function type(): string
    {
        return 'sfm.site.config';
    }

    public function __construct(public readonly int $siteId) {}

    public static function from(array $payload): static
    {
        return new static($payload['site_id']);
    }

    public function handle(): void
    {
        Cache::forget("site:config:{$this->siteId}");
    }
}
```

Handlers that implement `ShouldQueue` are pushed to the Laravel queue when an event arrives, where they benefit from retries, the `failed_jobs` table, backoff, and all other queue features. Plain handlers without `ShouldQueue` are invoked synchronously in the listener process — you should only use them for cheap, idempotent operations.

### Discovering Handlers

Handlers are  registered  automatically. When the listener starts, the package picks up every concrete class that implements `MessageHandler`, and binds a queue for each. Add a new handler to your project and restart the worker — that's all.

The scan happens once at boot and takes a single filesystem walk. For very large applications you may extend `Jurager\Microservice\Bus\HandlerDiscovery` and override the singleton binding to narrow the scan path or filter handlers further.

### Running the Listener

To start consuming events, you may use the `microservice:listen` Artisan command:

```bash
php artisan microservice:listen
```

For each handler, the command declares a durable queue named `{service_name}.{type}` (for example, `api.sfm.site.config`) and binds it to the topic exchange with the routing key set to the event type. The listener then enters a long-running loop, processing one message at a time.

The command prints a single line per message so you may follow what's happening:

```
[2026-05-21 12:34:56] Listening for events: sfm.site.updated, sfm.site.config (DLQ → events.dlx)
[2026-05-21 12:35:01] RECV sfm.site.config from sfm
[2026-05-21 12:35:01] DONE sfm.site.config queued → App\Jobs\Cache\ForgetSiteConfig (3ms)
[2026-05-21 12:35:05] RECV sfm.site.updated from sfm
[2026-05-21 12:35:05] FAIL sfm.site.updated App\Jobs\Cache\ForgetSiteDomain → DLQ
```

You may control the worker's lifetime with the `--memory` and `--max-jobs` options. The worker stops after the given memory limit (in megabytes) or after processing the given number of messages, whichever comes first:

```bash
php artisan microservice:listen --memory=256 --max-jobs=1000
```

Pairing `--max-jobs` with a process supervisor allows for periodic restarts that reclaim memory and pick up code changes without the operational overhead of full opcache flushes.

### Liveness

A listener that loses its broker connection stops with a non-zero exit code, which any supervisor will notice. A listener wedged inside a handler or a blocking read stays "up" forever, and nothing notices at all. The `--heartbeat` option gives an external check something to look at:

```bash
php artisan microservice:listen --heartbeat=/tmp/listener.heartbeat
```

The file's modification time is refreshed on every pass of the consume loop, at most once every five seconds. Its age is therefore bounded by the slowest synchronous handler — queued handlers return immediately, so it stays within seconds for them. Under Kubernetes, a liveness probe reads that age directly:

```yaml
livenessProbe:
  exec:
    command:
      - /bin/sh
      - -c
      - test $(( $(date +%s) - $(stat -c %Y /tmp/listener.heartbeat) )) -lt 300
```

Pick a threshold above your slowest synchronous handler, or the probe will restart a listener that is merely busy.

### Dead-Letter Queues

When `MESSAGE_BUS_DLQ_ENABLED=true` (the default), the listener declares a dead-letter exchange (`events.dlx`) and a per-handler dead-letter queue alongside each main queue:

```
api.sfm.site.config       ← main queue, bound to "events" with key "sfm.site.config"
api.sfm.site.config.dlq   ← DLQ, bound to "events.dlx" with key "sfm.site.config"
```

Messages are routed to the DLQ when:

- The HMAC signature is missing or invalid
- The body is not valid JSON
- The envelope is not a JSON object
- A synchronous handler throws an exception

Queued handlers handle their own failures via the Laravel queue, so a `ShouldQueue` handler that throws will not reach the DLQ — the message was already ack'd when it was dispatched to the queue.

To inspect a dead-letter queue, you may use the RabbitMQ Management UI or the `rabbitmqadmin` CLI:

```bash
rabbitmqadmin get queue=api.sfm.site.config.dlq count=10
```

To disable dead-letter routing globally, set `MESSAGE_BUS_DLQ_ENABLED=false`. With the DLQ disabled, failed messages are acknowledged and discarded — useful for local development but loses observability of poison messages.

> **Migration note:** toggling DLQ for an existing service requires deleting the affected main queues first — RabbitMQ rejects redeclares that change the `x-dead-letter-exchange` argument.

### Graceful Shutdown

The listener responds to `SIGTERM` and `SIGINT`. On receiving either signal, the loop finishes processing the current message, closes the AMQP channel, and exits with status `0`. This makes the listener safe to manage with Supervisor's rolling restarts and Kubernetes pod lifecycle.

## Diagnostic Commands

### Listing Registered Events

To inspect the handlers your service has registered, you may use the `microservice:events` command:

```bash
php artisan microservice:events
```

The command prints a table of every event type from `config/messages.php` along with the handler class and execution mode:

```
+------------------+----------------------------------+--------+
| Type             | Handler                          | Mode   |
+------------------+----------------------------------+--------+
| sfm.site.updated | App\Jobs\Cache\ForgetSiteDomain  | queued |
| sfm.site.config  | App\Jobs\Cache\ForgetSiteConfig  | queued |
| sfm.site.regions | App\Jobs\Cache\ForgetSiteRegions | queued |
+------------------+----------------------------------+--------+
```

This is useful for diagnosing configuration before starting a listener and for confirming that newly added handlers are picked up.

### Emitting Events Manually

To trigger handlers without going through application code, you may use the `microservice:emit` command:

```bash
php artisan microservice:emit sfm.site.config '{"site_id":1}'
```

The command goes through the same `MessageBus::publish` path as a real publish — the envelope is built, signed, and forwarded to RabbitMQ. Subscribing services pick the event up via their own listeners. This is the recommended way to smoke-test the pipeline after a deploy.

## Production Deployment

The listener is a long-running process and should be managed by a supervisor such as Supervisor, systemd, or a Kubernetes Deployment. The following Supervisor configuration runs the worker, restarts it on crash, restarts it gracefully every 1000 messages, and stops it cleanly on shutdown:

```ini
[program:api-microservice-listener]
command=php /var/www/api/artisan microservice:listen --memory=256 --max-jobs=1000
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=30
```

When you deploy a new version of the application, send `SIGTERM` to the listener. It will finish the current message and exit; Supervisor will start a fresh process with the new code.

For observability, you should monitor the DLQ depth of each queue. A growing DLQ indicates poison messages, configuration drift, or a misbehaving publisher — none of which are visible from the listener's normal output.
