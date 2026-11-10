---
title: Handling events
weight: 51
---

A message handler implements the `MessageHandler` contract:

```php
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Microservice\Bus\Contracts\MessageHandler;

class ForgetProductCache implements MessageHandler, ShouldQueue
{
    use Queueable;

    public static function type(): string
    {
        return 'catalog.product.updated';
    }

    public function __construct(
        public readonly int $productId,
    ) {}

    public static function from(
        array $payload,
        string $type = '',
    ): static {
        return new static($payload['product_id']);
    }

    public function handle(CacheRepository $cache): void
    {
        $cache->forget("product:{$this->productId}");
    }
}
```

`type()` selects the event, `from()` builds the handler from its payload, and `handle()` processes it. Dependencies of `handle()` are resolved from the container.

`type()` may return several types as an array; the handler then receives each of them, with the type passed to `from()`.

## Message context

The listener puts the message's `message_id`, `message_type`, `message_publisher`, `request_id`, and `message_occurred_at` into Laravel's `Context`. They reach queued handlers too. Use `message_occurred_at` when a handler depends on when the event happened rather than when it arrived:

```php
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

public function handle(): void
{
    $occurredAt = Carbon::parse(Context::get('message_occurred_at'));

    // ...
}
```

## Queued handlers

A handler implementing `ShouldQueue` runs through Laravel's queue, with the usual retries, backoff, and `failed_jobs`. Run a queue worker alongside the event listener:

```bash
php artisan queue:work
```

## Synchronous handlers

Handlers without `ShouldQueue` run inside the listener. Use them for small operations. A thrown exception sends the message to the dead-letter queue when enabled.

## Handler discovery

Handlers implementing `MessageHandler` are discovered in the application directory.

Register package handlers explicitly or configure directories to scan:

```php
// config/microservice.php
'bus' => [
    'handlers' => [
        \Vendor\Catalog\Handlers\SyncProduct::class,
    ],
    'discover' => [app_path('Messages')],
],
```

## Dynamic event types

`type()` may return a list of event types based on application data. The listener can [refresh subscriptions](running-listener.md#refreshing-subscriptions) without restarting.

When a type is removed, its queue remains. Messages accumulate until the subscription becomes active again.

Start [the listener](running-listener.md) to consume events for these handlers.
