---
title: Message Bus
weight: 50
---

## Introduction

The package provides an inter-service event bus over RabbitMQ, built directly on [php-amqplib/php-amqplib](https://github.com/php-amqplib/php-amqplib). You may use it to broadcast domain events between services without coupling them through HTTP calls. The bus ships its own publisher, consumer, ECDSA envelope signing, and dead-letter routing, so no higher-level transport wrapper is required on top of it.

The bus is opt-in per service. A service that doesn't publish or consume events pays nothing at runtime for it — the bus simply isn't activated, and none of its connections are opened.

## Configuration

The bus is configured in `config/microservice.php` under the `bus` key. The defaults are sensible for local development and may be overridden via environment variables:

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

All events are published to a single topic exchange (`events` by default), routed by the event type as the routing key. This lets multiple services subscribe to the same event independently, each with their own queue, without the publisher knowing or caring who's listening.

`MESSAGE_BUS_CONFIRM_TIMEOUT` is how long the publisher waits for the broker to confirm a message before giving up on that attempt — see [Delivery](#delivery) below for what happens next. `RABBITMQ_HEARTBEAT` and `RABBITMQ_TIMEOUT` are passed straight through to the underlying AMQP connection: the heartbeat is how often the client and broker exchange keepalive frames to detect a connection that's gone silent, and the timeout bounds both connecting and individual reads/writes.

### Disabling the Message Bus

For services that don't need to participate in inter-service messaging, you may disable the bus entirely:

```env
MESSAGE_BUS_ENABLED=false
```

With the bus disabled, `MessageBus::publish` logs a debug line and returns immediately — no AMQP connection is attempted, so a service with the bus off never even needs RabbitMQ to be reachable. The `microservice:listen` command refuses to start under the same condition. The package imposes no other runtime cost when the bus is off.

## Publishing Events

To publish an event, inject the `MessageBus` and call the `publish` method:

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

Event types follow the convention `{source_service}.{entity}.{event}`, for example `sfm.site.updated` or `oms.order.shipped`. The type becomes the AMQP routing key, so subscribing services can bind queues to a specific event or to a wildcard pattern such as `sfm.site.*`.

### Delivery

A publish either reaches a queue or throws — there's no third, silent outcome. Events are published with confirms enabled and the `mandatory` flag set, so the publisher actively waits for the broker to account for each one rather than firing and forgetting. An event that matches no bound queue is returned by the broker and raises a `RuntimeException`, rather than being silently discarded the way a topic exchange normally discards a message when nothing has ever declared a matching queue:

```php
try {
    $this->bus->publish('sfm.site.updated', ['site_id' => $event->siteId]);
} catch (Throwable $e) {
    // The event did not reach a queue.
}
```

Since a failed publish throws, a handler that already changed state before publishing should publish inside the same database transaction, or be prepared to compensate if the publish fails after the transaction commits. Nothing about this bus is designed to lose events quietly — a failure is always visible at the call site.

The `MessageBus` is registered as a singleton and its connection is reused across publishes, but not indefinitely: a connection that's been idle longer than `MESSAGE_BUS_MAX_IDLE` is replaced before use. This matters because a dead TCP session gets dropped by NAT or the broker without notifying the client — the loss would otherwise only surface on the *next* write, with the message it was carrying already gone. Reuse stays cheap under steady load and can never go stale between rare, bursty events.

If a connection is still found to be closed within that window despite the idle check, the publish is retried on a fresh one, up to `MESSAGE_BUS_PUBLISH_ATTEMPTS` times (set it to `1` to disable retrying entirely). Unroutable events are never retried on a new connection — the connection was fine, there's simply nothing bound to that routing key, and retrying wouldn't change that.

### The Envelope

Every published event is wrapped in a standard envelope containing metadata alongside the domain payload:

```json
{
  "type": "sfm.site.updated",
  "service": "sfm",
  "occurred_at": "2026-05-21T10:30:00+00:00",
  "request_id": "req_abc123",
  "payload": {"site_id": 1, "domain": "example.com"},
  "signature": "9f8a4b…",
  "certificate": "eyJzZXJ2aWNlIjoic2ZtIiw…"
}
```

`service` is taken from `microservice.name`, `occurred_at` is the publish timestamp in ISO 8601, and `request_id` is propagated from the inbound HTTP request's `X-Request-Id` header when one is available — which lets you trace a single incoming request all the way through however many events it ends up producing.

### Signing Envelopes

Every envelope is signed with the publisher's own private key before publishing, and carries its certificate alongside the signature. The consumer checks that the certificate is signed by the trusted cluster CA and names the service the envelope claims to be from — the same check `TrustPeer` applies to HTTP requests, see [How Peer Trust Works](security.md#how-peer-trust-works) — then verifies the signature against the public key inside it before invoking the handler. An envelope with a missing or invalid signature, or a certificate that doesn't check out, is rejected outright and routed to the dead-letter queue rather than handed to a handler.

This protects consumers from anything with mere broker access but no valid publisher key — publishing to the exchange directly, bypassing the application entirely, still isn't enough to forge an event. And because each service signs with its own key, compromising one publisher's key never lets an attacker forge events *from any other service*.

> [!WARNING]
> Signature verification is skipped when `microservice.debug=true`, mirroring the HTTP `TrustPeer` middleware. Never enable debug mode in production.

## Consuming Events

### Defining Message Handlers

A handler is a class that implements the `MessageHandler` contract. It declares the event type it processes, and knows how to reconstruct itself from a domain payload:

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

    public static function from(array $payload, string $type = ''): static
    {
        return new static($payload['site_id']);
    }

    public function handle(): void
    {
        Cache::forget("site:config:{$this->siteId}");
    }
}
```

Handlers that implement `ShouldQueue` are pushed to the Laravel queue as soon as an event arrives, where they benefit from retries, the `failed_jobs` table, backoff, and every other queue feature you're already used to. Plain handlers without `ShouldQueue` are invoked synchronously, right inside the listener process — reserve those for work that's cheap and safe to run twice, since a synchronous handler that throws is treated as a failed delivery, not retried the way a queued job would be.

### Discovering Handlers

Handlers are registered automatically. When the listener starts, the package scans for every concrete class that implements `MessageHandler` and binds a queue for each type it finds. Add a new handler class to your project and restart the worker — that's the entire setup, there's nothing to register by hand.

The scan happens once at boot and takes a single filesystem walk, so it doesn't add meaningfully to startup time even in a large application. If you do need to narrow the scan path, or filter which handlers are picked up, extend `Jurager\Microservice\Bus\HandlerDiscovery` and override its singleton binding.

A handler's `type()` doesn't have to be a fixed string — it can compute its answer at call time (query a table of active subscriptions, read a config value, and so on), in which case the set of types it wants can change while the worker is already running. See [Rescanning for New Types](#rescanning-for-new-types) below for how the listener picks that up without a restart.

### Rescanning for New Types

The filesystem walk that finds handler *classes* only needs to run once — but what a handler's `type()` reports can change at runtime, e.g. a handler that derives its types from a database table of active subscriptions. Restarting the worker every time such a subscription changes is exactly the operational overhead this is meant to avoid, so the listener re-evaluates `type()` on its already-discovered handlers periodically, on its own, and binds a queue for any type that's newly reporting:

```bash
php artisan microservice:listen --rescan=30
```

`--rescan` is in seconds and defaults to 30; pass `0` to disable it and go back to the fixed, boot-time-only handler set. A rescan is cheap — it doesn't touch the filesystem, just calls `type()` again on the classes already found — so a short interval is fine even for a handler with many types.

Rescanning is deliberately additive only: a type that stops being reported stays subscribed rather than being unbound. Write handlers to no-op gracefully for a type they no longer care about (the common case — check the condition that made the type active in the first place, and return early if it no longer holds) rather than relying on the listener to tear down the subscription for you.

If every handler currently reports zero types — nothing has activated a subscription yet — the listener doesn't exit; with `--rescan` enabled it keeps the broker connection open and waits, picking up the first type as soon as one appears:

```
[2026-05-21 12:34:56] No event types active yet — waiting (rescanning every 30s)
[2026-05-21 12:35:26] Now also listening for: sfm.site.config
```

### Running the Listener

To start consuming events, use the `microservice:listen` Artisan command:

```bash
php artisan microservice:listen
```

For each discovered handler, the command declares a durable queue named `{service_name}.{type}` (for example, `api.sfm.site.config`) and binds it to the topic exchange with the routing key set to that event type. The listener then enters a long-running loop, processing one message at a time.

The command prints a single line per message, so you can follow along in real time or in your log aggregator:

```
[2026-05-21 12:34:56] Listening for events: sfm.site.updated, sfm.site.config (DLQ → events.dlx)
[2026-05-21 12:35:01] RECV sfm.site.config from sfm
[2026-05-21 12:35:01] DONE sfm.site.config queued → App\Jobs\Cache\ForgetSiteConfig (3ms)
[2026-05-21 12:35:05] RECV sfm.site.updated from sfm
[2026-05-21 12:35:05] FAIL sfm.site.updated App\Jobs\Cache\ForgetSiteDomain → DLQ
```

You may control the worker's lifetime with the `--memory` and `--max-jobs` options — the process stops after whichever limit is hit first, the given memory usage (in megabytes) or the given number of processed messages:

```bash
php artisan microservice:listen --memory=256 --max-jobs=1000
```

Pairing `--max-jobs` with a process supervisor gives you periodic restarts that reclaim memory and pick up newly deployed code, without the operational overhead of a full opcache flush on every deploy.

### Losing the Broker

A dropped connection isn't a crash. The listener reopens it, redeclares its queues, and resumes consuming — waiting a little longer before each attempt: one second, then two, four, and so on up to `--backoff`:

```bash
php artisan microservice:listen --max-reconnects=10 --backoff=30
```

With the defaults shown above, this covers roughly three minutes of outage before giving up. After `--max-reconnects` failed attempts the process exits with a non-zero status and lets the supervisor take over — a broker that's genuinely staying down isn't something the reconnect loop itself can fix, and restarting the whole process is already handled at that layer. Pass `--max-reconnects=0` to keep trying indefinitely instead.

An outage never costs you a message: the listener only acknowledges a message after its handler has succeeded, and prefetch is fixed at one, so at most a single unacknowledged message is ever in flight — the broker simply requeues it once the connection comes back.

### Liveness

A listener wedged inside a handler, or stuck on a blocking read, still looks "up" to the outside world forever, with nothing noticing on its own. The `--heartbeat` option gives an external check something concrete to look at:

```bash
php artisan microservice:listen --heartbeat=/tmp/listener.heartbeat
```

The file's modification time is refreshed on every pass of the consume loop — at most once every five seconds — and keeps being refreshed while the listener is waiting to reconnect, so a process that's alive and still trying isn't mistaken for a dead one. Its age is bounded by the slowest synchronous handler in your application; queued handlers hand off immediately, so with those the heartbeat stays within a few seconds no matter how long the queued job itself takes to actually run. Under Kubernetes, a liveness probe can read that age directly:

```yaml
livenessProbe:
  exec:
    command:
      - /bin/sh
      - -c
      - test $(( $(date +%s) - $(stat -c %Y /tmp/listener.heartbeat) )) -lt 300
```

> [!TIP]
> Pick a threshold comfortably above your slowest synchronous handler, or the probe will restart a listener that's merely busy, not actually stuck.

### Dead-Letter Queues

When `MESSAGE_BUS_DLQ_ENABLED=true` (the default), the listener declares a dead-letter exchange (`events.dlx`) and a per-handler dead-letter queue alongside each main queue:

```
api.sfm.site.config       ← main queue, bound to "events" with key "sfm.site.config"
api.sfm.site.config.dlq   ← DLQ, bound to "events.dlx" with key "sfm.site.config"
```

A message is routed to the DLQ instead of being processed when: its signature or certificate is missing or invalid, or the certificate doesn't name the service the envelope claims to be from; its body isn't valid JSON; the decoded envelope isn't a JSON object; or a synchronous handler throws while processing it.

Queued handlers are the one exception — they handle their own failures through the Laravel queue's own retry and `failed_jobs` machinery, so a `ShouldQueue` handler that throws never reaches the DLQ. By the time it fails, the original AMQP message has already been acknowledged, because dispatching it to the queue is what counted as "delivered" from the bus's point of view.

To inspect a dead-letter queue, use the RabbitMQ Management UI or the `rabbitmqadmin` CLI:

```bash
rabbitmqadmin get queue=api.sfm.site.config.dlq count=10
```

To disable dead-letter routing globally, set `MESSAGE_BUS_DLQ_ENABLED=false`. With the DLQ disabled, failed messages are acknowledged and discarded instead — fine for local development, but it means losing all visibility into poison messages in any environment you actually care about.

> [!WARNING]
> Toggling DLQ for an existing service requires deleting the affected main queues first — RabbitMQ rejects redeclares that change the `x-dead-letter-exchange` argument of a queue that already exists.

### Graceful Shutdown

The listener responds to `SIGTERM` and `SIGINT`. On receiving either signal, the loop finishes processing whatever message it's currently on, closes the AMQP channel cleanly, and exits with status `0` — which is what makes it safe to manage with Supervisor's rolling restarts or a Kubernetes pod's normal termination lifecycle.

A signal that arrives in the middle of an outage is honoured immediately, rather than being queued up behind the remaining reconnect attempts: the listener stops right away, so a deploy is never held up waiting on a broker that happens to be unreachable at that exact moment.

## Diagnostic Commands

### Listing Registered Events

To inspect the handlers your service has registered, use the `microservice:events` command:

```bash
php artisan microservice:events
```

The command prints a table of every discovered event type, along with the queue it binds, the handler class, and whether it runs queued or synchronously:

```
+------------------+----------------------+----------------------------------+--------+
| Type             | Queue                | Handler                          | Mode   |
+------------------+----------------------+----------------------------------+--------+
| sfm.site.updated | api.sfm.site.updated | App\Jobs\Cache\ForgetSiteDomain  | queued |
| sfm.site.config  | api.sfm.site.config  | App\Jobs\Cache\ForgetSiteConfig  | queued |
| sfm.site.regions | api.sfm.site.regions | App\Jobs\Cache\ForgetSiteRegions | queued |
+------------------+----------------------+----------------------------------+--------+
```

This is useful for confirming a newly added handler was actually picked up, and for diagnosing configuration before you start a listener in the first place, rather than finding out something's missing once events are already flowing.

### Emitting Events Manually

To trigger handlers without going through your actual application code, use the `microservice:emit` command:

```bash
php artisan microservice:emit sfm.site.config '{"site_id":1}'
```

The command goes through the exact same `MessageBus::publish` path as a real publish — the envelope is built, signed, and forwarded to RabbitMQ — so subscribing services pick it up through their own listeners exactly as they would a real event.

> [!TIP]
> This is the recommended way to smoke-test the whole pipeline after a deploy, without waiting for a real domain event to trigger it.

## Production Deployment

The listener is a long-running process and should be managed by a supervisor such as Supervisor, systemd, or a Kubernetes Deployment. The following Supervisor configuration runs the worker, restarts it on crash, restarts it gracefully every 1000 messages to reclaim memory, and stops it cleanly on shutdown:

```ini
[program:api-microservice-listener]
command=php /var/www/api/artisan microservice:listen --memory=256 --max-jobs=1000 --heartbeat=/tmp/listener.heartbeat
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=30
```

When you deploy a new version of the application, send `SIGTERM` to the listener. It finishes the current message and exits; Supervisor starts a fresh process running the new code, and no message is lost in between.

> [!TIP]
> Monitor the DLQ depth of each queue in production. A growing DLQ indicates poison messages, configuration drift, or a misbehaving publisher — none of which are visible from the listener's normal output alone.
