---
title: Running the event listener
weight: 52
---

Start a listener after [creating a handler](handling-events.md):

```bash
php artisan microservice:listen
```

Keep this process running with Supervisor, systemd, or Kubernetes. Each event type uses a queue named `{service}.{type}`, for example `api.catalog.product.updated`.

## Worker limits

You can limit memory usage in MB and the number of processed messages:

```bash
php artisan microservice:listen --memory=256 --max-jobs=1000
```

The listener exits when either limit is reached. Configure your process manager to restart it.

## Reconnecting

The listener reconnects automatically with exponential backoff:

```bash
php artisan microservice:listen --max-reconnects=10 --backoff=30
```

`--backoff` caps the delay in seconds. Set `--max-reconnects=0` to retry indefinitely. Messages not acknowledged before a lost connection may be delivered again.

## Refreshing subscriptions

For handlers whose `type()` result changes at runtime, set the rescan interval:

```bash
php artisan microservice:listen --rescan=30
```

The interval is in seconds; `0` disables rescanning. The listener rechecks known handlers rather than discovering new classes. Restart it when deploying new handlers.

## Liveness

Write a heartbeat file so your process monitor can check the listener:

```bash
php artisan microservice:listen --heartbeat=/tmp/listener.heartbeat
```

Check that the file is recent. Allow more time than your longest synchronous handler takes; the file is also updated during reconnection attempts.

## Dead-letter queues

Rejected messages go to a queue named `{service}.{type}.dlq` by default:

```text
api.catalog.product.updated
api.catalog.product.updated.dlq
```

Invalid JSON, invalid signatures, and synchronous handler failures are sent there. Queued handler failures use Laravel's queue system instead.

Inspect a queue with the RabbitMQ Management UI or `rabbitmqadmin`:

```bash
rabbitmqadmin get queue=api.catalog.product.updated.dlq count=10
```

Set `MESSAGE_BUS_DLQ_ENABLED=false` to discard rejected messages instead. `MESSAGE_BUS_DLQ_EXCHANGE` changes the exchange used for dead letters.

Changing dead-letter settings for existing queues may require recreating the affected queues. RabbitMQ does not allow redeclaring a queue with different dead-letter arguments.

## Graceful shutdown

The listener finishes the current message before stopping on `SIGTERM`, `SIGINT`, or `php artisan queue:restart`.

For example, you can use this Supervisor configuration:

```ini
[program:microservice-listener]
command=php /var/www/app/artisan microservice:listen --memory=256 --max-jobs=1000
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=30
```

Set the shutdown timeout long enough for the current handler to finish.

## Diagnostic commands

List discovered events, handlers, queues, and execution modes:

```bash
php artisan microservice:events
```

Publish a test event:

```bash
php artisan microservice:emit catalog.product.updated '{"product_id":42}'
```

Monitor dead-letter queue depth in production to catch rejected events and failing handlers.
