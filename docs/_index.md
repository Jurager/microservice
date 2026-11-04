---
title: Microservice
weight: 1
---

This package lets Laravel services communicate over HTTP and RabbitMQ. You can send signed requests, expose service routes through a gateway, and publish events.

```php
use Jurager\Microservice\Facades\Microservice;

$response = Microservice::service('catalog')->get('/v1/products')->send();
```

## Getting started

You need PHP 8.4, Laravel 13.37 or higher, and a shared cache with atomic locks. RabbitMQ is needed only for events.

Start with [installation](installation.md) and [signing credentials](certificates.md).

## HTTP requests

- [Sending requests](client.md)
- [Reading JSON:API responses](responses.md)
- [Fetching in batches](batching.md)
- [Retries and circuit breaker](resilience.md)
- [Testing service requests](testing.md)

## Serving requests

- [Verifying requests and preventing duplicates](security.md)
- [Returning JSON:API resources](json-api.md)
- [Setting up a gateway](gateway.md)
- [Checking service health](health.md)

## Events

- [Publishing events](message-bus.md)
- [Handling events](handling-events.md)
- [Running the listener](running-listener.md)

See the [changelog](../CHANGELOG.md) for upgrade notes.
