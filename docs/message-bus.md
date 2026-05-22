# Message Bus

Inter-service event bus over RabbitMQ. The package ships its own publisher (`MessageBus`), consumer (`microservice:listen` command), `MessageHandler` contract, and HMAC envelope signing — built directly on [php-amqplib/php-amqplib](https://github.com/php-amqplib/php-amqplib), no higher-level transport wrappers.

The bus can be disabled per-service via `microservice.bus.enabled=false` — `publish()` becomes a no-op and the listen command refuses to start. Services that don't need RabbitMQ pay nothing.

## Setup

```bash
composer require jurager/microservice
```

Add to `.env`:

```env
MESSAGE_BUS_ENABLED=true
MESSAGE_BUS_EXCHANGE=events

RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
```

No config publishing, no extra commands.

---

## Publishing

Inject `MessageBus` and call `publish`:

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

`MessageBus` is a singleton. Internally it builds a signed envelope and publishes to a **topic exchange** (`events` by default) with the event type as the routing key. The AMQP connection is lazy — opened on first publish.

Publishing failures are caught and logged — they do not throw.

The envelope on the wire:

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

**Type naming convention:** `{source_service}.{entity}.{event}`

### Envelope signature

Every envelope is HMAC-signed with `microservice.secret` before publishing. The consumer verifies the signature before invoking the handler — envelopes with missing or invalid signatures are dropped (logged as warning). Verification is skipped when `microservice.debug=true` (mirrors HTTP `TrustGateway` behavior).

This protects consumers from messages forged by anything that has access to the broker but doesn't share the cluster secret.

---

## Consuming

### 1. Implement MessageHandler

A handler is typically a standard Laravel queued job that also implements `MessageHandler`:

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

    public static function fromMessage(array $payload): static
    {
        return new static($payload['site_id']);
    }

    public function handle(): void
    {
        Cache::forget("site:config:{$this->siteId}");
    }
}
```

### 2. Register in config/messages.php

```php
return [
    \App\Jobs\Cache\ForgetSiteConfig::class,
    \App\Jobs\Inventory\SyncStock::class,
];
```

### 3. Run the worker

```bash
php artisan microservice:listen
```

For each handler `type()` in `config/messages.php`, the worker:
1. Declares a durable queue named `{service_name}.{type}` (e.g. `api.sfm.site.config`).
2. Binds it to the topic exchange with routing key = type.
3. Starts consuming.

When a message arrives:
- The envelope signature is verified — failure → ack + log warning (no requeue, poison messages are not retried).
- The handler is constructed via `fromMessage($envelope['payload'])`.
- If it implements `ShouldQueue` → `dispatch($handler)` and ack (Laravel queue owns retries).
- Otherwise → `$handler->handle()` synchronously, ack on success or after exception (logged).

The worker stays non-blocking even with heavy handlers since `ShouldQueue` ones don't run inline.

#### Options

```bash
php artisan microservice:listen --memory=256 --max-jobs=1000
```

- `--memory=N` — stop when process RSS reaches N MB.
- `--max-jobs=N` — stop after N messages (useful for periodic restarts via supervisor).

Graceful shutdown via `SIGTERM`/`SIGINT` — the loop finishes the current message and exits cleanly. Supervisor can safely send signals for rolling restarts.

#### Supervisor

```ini
[program:api-microservice-listener]
command=php /var/www/api/artisan microservice:listen --memory=256 --max-jobs=1000
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=30
```

---

## Diagnostic commands

### `microservice:events`

Print a table of every event type registered in `config/messages.php` with the handler class and execution mode:

```bash
$ php artisan microservice:events
+---------------------+--------------------------------------+--------+
| Type                | Handler                              | Mode   |
+---------------------+--------------------------------------+--------+
| sfm.site.updated    | App\Jobs\Cache\ForgetSiteDomain      | queued |
| sfm.site.config     | App\Jobs\Cache\ForgetSiteConfig      | queued |
| sfm.site.regions    | App\Jobs\Cache\ForgetSiteRegions     | queued |
+---------------------+--------------------------------------+--------+
```

### `microservice:emit`

Manually publish an event to the bus — useful for triggering handlers without going through application code:

```bash
php artisan microservice:emit sfm.site.updated '{"site_id":1,"domain":"example.com"}'
```

Goes through the same `MessageBus::publish` path as a real publish: envelope is built, signed, sent to AMQP. Subscribers in other services pick it up via their `microservice:listen`.

---

## Disabling the bus

For services that don't publish or consume events:

```env
MESSAGE_BUS_ENABLED=false
```

- `MessageBus::publish()` logs a debug line and returns — no AMQP connection is attempted.
- `microservice:listen` refuses to start.
- The package itself imposes no runtime cost.

---

## Architecture

```
src/Bus/
├── Contracts/
│   └── MessageHandler.php   — public contract for consumer handlers
├── Connection.php           — lazy AMQPStreamConnection/channel wrapper, declares topic exchange
├── MessageBus.php           — publish: envelope + HMAC + channel->publish
└── Listener.php             — verify + route to ShouldQueue or sync handler (no AMQP)

src/Commands/
├── ListenCommand.php        — consumer loop: declare queue, bind, consume, dispatch
├── EventsCommand.php        — diagnostic: list registered handlers
└── EmitCommand.php          — diagnostic: manual publish
```

The AMQP transport is encapsulated in `Connection` + `ListenCommand`. `Listener` is pure dispatch logic — unit-testable without a broker.

## MessageBus

```php
namespace Jurager\Microservice\Bus;

class MessageBus
{
    public function publish(string $type, array $payload, ?string $queue = null): void;
    public function verify(array $envelope): bool;
    public function enabled(): bool;
}
```

The `$queue` parameter is kept for backward compatibility and is ignored — routing is by event type via the topic exchange.

## MessageHandler interface

```php
namespace Jurager\Microservice\Bus\Contracts;

interface MessageHandler
{
    public static function type(): string;
    public static function fromMessage(array $payload): static;
}
```

A handler that also implements `ShouldQueue` benefits from retries, the failed jobs table, and all other queue features via the standard Laravel queue worker.
