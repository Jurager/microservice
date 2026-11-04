---
title: Testing service requests
weight: 24
---

You can fake responses by service name with `Microservice::fake()`:

```php
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jurager\Microservice\Facades\Microservice;

Microservice::fake([
    'catalog' => [Http::response(['data' => ['id' => '42']])],
]);

$response = Microservice::service('catalog')->get('/v1/products/42')->send();

Microservice::assertSent(fn (Request $request, string $service) =>
    $service === 'catalog' && $request->url() === 'http://catalog.test/v1/products/42'
);

Microservice::assertSentCount(1);
```

Each service uses its own queue of fake responses. Responses are consumed in order; faking the same service again appends to that queue.

## Testing errors

Queue an error response to check how your application handles it:

```php
Microservice::fake([
    'catalog' => [Http::response(status: 404)],
]);

$response = Microservice::service('catalog')
    ->get('/v1/products/42')
    ->withoutThrowing()
    ->send();

$this->assertSame(404, $response->status());
```

Fakes accept Laravel HTTP responses, arrays, status codes, PSR-7 responses, and exceptions. Supply an exception to simulate a transport failure.

## Asserting requests

```php
Microservice::assertNotSent(fn (Request $request, string $service) => $service === 'orders');
```

In a test that should make no service calls, use `Microservice::assertNothingSent()`.

Laravel's `Http::fake()`, `Http::assertSent()`, and `Http::preventStrayRequests()` also work. If you use `FakesServiceClient` in a test class, `$this->fakeService('catalog', $responses)` is shorthand for faking one service.
