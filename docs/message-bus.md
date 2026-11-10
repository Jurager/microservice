---
title: Publishing events
weight: 50
---

You can publish events to other services using RabbitMQ:

```php
use Jurager\Microservice\Facades\MessageBus;

MessageBus::publish('catalog.product.updated', ['product_id' => $product->id]);
```

Events use the publishing service's signing key and certificate. The receiving service verifies them before dispatching a handler.

## Configuration

Set your RabbitMQ connection in `.env`:

```env
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
```

The bus is enabled by default, but opens a connection only when publishing or consuming. Set `MESSAGE_BUS_ENABLED=false` to disable publishing and listeners.

For TLS, set `RABBITMQ_SECURE=true` and configure certificates if required by your broker. Other options are explained in [config/microservice.php](https://github.com/Jurager/microservice/blob/main/config/microservice.php).

## Publishing events

Choose a type such as `{service}.{entity}.{event}` and pass an array payload:

```php
MessageBus::publish('orders.order.shipped', ['order_id' => $order->id]);
```

A handler can subscribe to an exact type or a topic pattern such as `orders.order.*`.

### Delivery

Publishing waits for broker confirmation and throws if no queue matches the event. Start [a listener](running-listener.md) to declare subscriptions before publishing.

`MESSAGE_BUS_PUBLISH_ATTEMPTS` controls the total attempts, including the first. Connection failures may retry; unroutable events do not.

### Publishing inside a transaction

Events are published after the current database transaction commits:

```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($order) {
    $order->ship();

    MessageBus::publish('orders.order.shipped', ['order_id' => $order->id]);
});
```

Rolled-back transactions publish nothing. Outside a transaction, events are sent immediately. If publishing fails after commit, the exception reaches the caller but the database changes remain committed.

Set `MESSAGE_BUS_AFTER_COMMIT=false` to always publish immediately.

## Event envelope

Each event is sent as a signed envelope:

```json
{
    "id": "0b5c9f6e-7a2b-4c1d-9e3f-2a1b0c9d8e7f",
    "type": "orders.order.shipped",
    "service": "orders",
    "occurred_at": "2026-11-09T14:30:05+03:00",
    "request_id": "a1a2a3a4-b1b2-c1c2-d1d2-d3d4d5d6d7d8",
    "payload": {"order_id": 42},
    "signature": "...",
    "certificate": "..."
}
```

`occurred_at` is the time of publishing in ISO 8601. When an event is published later than it happened, for example from a queued job, pass the time of the fact:

```php
MessageBus::publish('orders.order.shipped', ['order_id' => $order->id], $order->shipped_at);
```

Any `DateTimeInterface` is accepted. The signature covers `occurred_at`, so receivers can trust it.

Handlers receive the payload. Their log context includes `message_id`, `message_type`, `message_publisher`, `request_id`, and `message_occurred_at`.

Events can be delivered more than once. Make handlers safe to repeat, using `Context::get('message_id')` to identify duplicate events when needed.

## Consuming events

[Create a handler](handling-events.md) to process events synchronously or through Laravel's queue.

## Handler discovery

Handlers are discovered in the application directory. You can also [register handlers or scan other directories](handling-events.md#handler-discovery).

## Running the listener

Run `php artisan microservice:listen`. See [listener options and deployment](running-listener.md).

## Dead-letter queues

Invalid messages and failed synchronous handlers go to [dead-letter queues](running-listener.md#dead-letter-queues). Queued handler failures use Laravel's queue system.
