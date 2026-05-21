# Message Bus

Inter-service event bus over RabbitMQ. The package provides a publisher (`MessageBus`), a `MessageHandler` contract for typed handlers, and a thin auto-registration layer on top of [nuwber/rabbitevents](https://github.com/rabbitevents/listener).

`nuwber/rabbitevents` is a hard dependency of the package, so the publisher and listener are always available. Using them is optional — services that don't publish events or run a listener pay nothing.

## Requirements

`RabbitEvents\Publisher\PublisherServiceProvider` and `RabbitEvents\Listener\ListenerServiceProvider` are auto-discovered by Laravel.

The `MicroserviceServiceProvider` automatically creates an empty `app/Listeners` directory if it doesn't exist — nuwber's listener auto-discovery would otherwise fail at boot in services that don't have any class-based listeners.

Add to `.env`:

```env
RABBITEVENTS_HOST=127.0.0.1
RABBITEVENTS_PORT=5672
RABBITEVENTS_USER=guest
RABBITEVENTS_PASSWORD=guest
RABBITEVENTS_VHOST=events
```

> `RABBITEVENTS_VHOST` defaults to `events` (not `/`). Create the vhost in RabbitMQ or override to `/`.

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

`MessageBus` is a singleton concrete class. Internally it builds a signed envelope and forwards to RabbitMQ via nuwber's `publish()` helper. Publishing failures are caught and logged — they do not throw.

The envelope sent on the wire wraps the domain payload with metadata and an HMAC signature:

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

Every envelope is HMAC-signed with `microservice.secret` before publishing. The auto-registered listener verifies the signature before invoking the handler — envelopes with missing or invalid signatures are logged and dropped. Verification is skipped when `microservice.debug=true` (mirrors HTTP `TrustGateway` behavior).

This protects consumers from messages forged by anything that has access to the broker but doesn't share the cluster secret.

---

## Consuming

### 1. Implement MessageHandler

A handler is a standard Laravel queued job that also implements `MessageHandler`:

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

Create `config/messages.php` with a flat list of handler classes:

```php
return [
    \App\Jobs\Cache\ForgetSiteConfig::class,
    \App\Jobs\Inventory\SyncStock::class,
];
```

`MicroserviceServiceProvider` subscribes a listener on the RabbitEvents `Dispatcher` for each handler's `type()`. When `rabbitevents:listen` receives an AMQP message it fires the local event; the subscribed listener verifies the envelope signature, then routes the handler:

- If it implements `Illuminate\Contracts\Queue\ShouldQueue` → `dispatch($handler)` (pushed to the Laravel queue, processed by a regular `queue:work` worker with retries, backoff, failed_jobs).
- Otherwise → `$handler->handle()` is invoked synchronously in the listener process.

The `rabbitevents:listen` process stays non-blocking even with heavy handlers — it only pushes to the queue and continues consuming. Plain `MessageHandler` (without `ShouldQueue`) should only be used for cheap operations.

### 3. Run the worker

```bash
php artisan rabbitevents:listen
```

Without arguments, `rabbitevents:listen` picks up **all** events registered on the dispatcher — which is exactly what our auto-registration produces from `config/messages.php`. Adding a new handler to the config requires no command-line change, just a restart of the worker.

Restrict to specific types for debugging:

```bash
php artisan rabbitevents:listen sfm.site.updated,sfm.site.config
```

Standard options are supported: `--memory`, `--timeout`, `--tries`, `--sleep`. See [nuwber's listener docs](https://github.com/rabbitevents/listener) for the full list.

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

Goes through the same `MessageBus::publish` path as a real publish: envelope is built, signed and forwarded to RabbitMQ via nuwber's Publisher. Subscribers in other services pick it up via their listener.

---

## Production

Manage the listener with Supervisor:

```ini
[program:api-rabbitevents-listener]
command=php /var/www/api/artisan rabbitevents:listen --memory=256 --timeout=120
autostart=true
autorestart=true
stopwaitsecs=10
```

---

## MessageBus

```php
namespace Jurager\Microservice\Bus;

class MessageBus
{
    public function publish(string $type, array $payload, ?string $queue = null): void;
}
```

The `$queue` parameter is kept for backward compatibility with the previous queue-driver implementation and is ignored — routing in rabbitevents is by event name.

## MessageHandler interface

```php
namespace Jurager\Microservice\Bus\Contracts;

interface MessageHandler
{
    // The message type this handler processes
    public static function type(): string;

    // Construct the handler from the raw domain payload (envelope already unwrapped)
    public static function fromMessage(array $payload): static;
}
```

A handler that also implements `ShouldQueue` benefits from retries, the failed jobs table, and all other queue features.
