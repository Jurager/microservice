# Message Bus

Inter-service messaging over RabbitMQ. The package provides a publisher (`MessageBus`), a generic deserializer (`MessageDeserializer`), and a `MessageHandler` contract for typed message handling.

## Requirements

```bash
composer require vladimir-yuldashev/laravel-queue-rabbitmq
```

Add to `.env`:

```env
MESSAGE_BUS_CONNECTION=rabbitmq

RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
```

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
            queue: 'api.sfm.cache',
        );
    }
}
```

`MessageBus` is registered as a singleton by the service provider. Publishing failures are caught and logged — they do not throw exceptions.

Message envelope written to the queue:

```json
{"type": "sfm.site.updated", "payload": {"site_id": 1, "domain": "example.com"}}
```

**Type naming convention:** `{source_service}.{entity}.{event}`

---

## Consuming

### 1. Configure the queue driver

In `config/queue.php`, add a `rabbitmq` connection with `MessageJob`:

```php
'rabbitmq' => [
    'driver' => 'rabbitmq',
    'hosts'  => [[
        'host'     => env('RABBITMQ_HOST', '127.0.0.1'),
        'port'     => env('RABBITMQ_PORT', 5672),
        'user'     => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost'    => env('RABBITMQ_VHOST', '/'),
    ]],
    'options' => [
        'queue' => [
            'job'           => \Jurager\Microservice\Queue\MessageJob::class,
            'exchange'      => '',
            'exchange_type' => 'direct',
        ],
    ],
],
```

### 2. Implement MessageHandler

Each handler is a standard Laravel queued job that also implements `MessageHandler`:

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

### 3. Register in config/messages.php

Create `config/messages.php` in the consuming service with a flat list of handler classes:

```php
return [
    \App\Jobs\Cache\ForgetSiteConfig::class,
    \App\Jobs\Inventory\SyncStock::class,
];
```

`MessageDeserializer` builds the `type → class` routing table from this list at boot by calling `::type()` on each class. No manual mapping required.

### 4. Run the worker

```bash
php artisan queue:work rabbitmq --queue=api.sfm.cache --tries=3
```

For production, manage the process with Supervisor:

```ini
[program:api-sfm-worker]
command=php /var/www/api/artisan queue:work rabbitmq --queue=api.sfm.cache --tries=3 --sleep=3
autostart=true
autorestart=true
stopwaitsecs=10
```

---

## MessageHandler interface

```php
namespace Jurager\Microservice\Bus\Contracts;

interface MessageHandler
{
    // The message type this handler processes
    public static function type(): string;

    // Construct the handler from the raw message payload
    public static function fromMessage(array $payload): static;
}
```

A handler class implementing `MessageHandler` and `ShouldQueue` is a standard Laravel job. It benefits from retries, the failed jobs table, and all other queue features out of the box.

---

## How message routing works

When the worker receives a raw RabbitMQ message, `MessageJob::payload()` intercepts it:

1. Decodes the JSON envelope: `{"type": "...", "payload": {...}}`
2. Looks up the handler class by `type` using the map built from `config/messages.php`
3. Calls `$class::fromMessage($payload)` to instantiate the handler
4. Returns a standard Laravel job payload (serialized command)
5. The worker executes `handle()` as a normal job

Standard Laravel job payloads (dispatched via `dispatch()`) pass through unchanged — `MessageJob` only intercepts the custom envelope format.
