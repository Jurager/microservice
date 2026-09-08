---
title: Client
weight: 20
---

## Introduction

The `ServiceClient` is the package's HTTP client for talking to other services. Every request it sends is automatically signed with this service's own private key, propagates distributed tracing headers, and resolves the destination service's URL from either a configured discovery pattern or the gateway's manifest registry — so you never hardcode another service's address.

On top of that, the client can retry failed requests, trip a circuit breaker against a service that's clearly down, and parse JSON:API responses into typed objects. None of that is required to get started, though — a plain request is just as simple as it looks below.

## Sending Requests

You may resolve the client from the container and start building a request with the `service` method:

```php
use Jurager\Microservice\Client\ServiceClient;

$client = app(ServiceClient::class);

$response = $client->service('pim')
    ->get('/v1/products/42')
    ->with(['include' => 'categories'])
    ->timeout(5)
    ->send();
```

The `service` method names the destination service and returns a `PendingServiceRequest`, a builder you configure before dispatching. The HTTP verb method (`get`, `post`, `put`, etc.) sets the method and path; the rest of the builder methods may be chained in any order after it. Nothing is actually sent over the wire until you call `send`.

### Available Methods

The HTTP verb starts the request. `post`, `put`, and `patch` accept an optional array as a second argument, sent as the JSON body:

```php
$client->service($name)->get(string $path);
$client->service($name)->post(string $path, ?array $body = null);
$client->service($name)->put(string $path, ?array $body = null);
$client->service($name)->patch(string $path, ?array $body = null);
$client->service($name)->delete(string $path);
```

Builder methods may be chained onto it in any order, before calling `send`:

```php
->with(array $query)          // merge query string parameters, dropping any null values
->merge(array $query)         // like with(), but deep-merges: comma strings are concatenated, arrays are merged recursively
->headers(array $headers)     // merge additional headers (withHeaders is an alias)
->withBody(array $body)       // set the JSON body directly, instead of passing it to post/put/patch
->fields(array $fields)       // merge fields into the body, dropping any null values — the body-side counterpart of with()
->withMultipart(array $parts) // send a multipart/form-data body instead of JSON — see Uploading Files below
->timeout(int $seconds)       // override the per-request timeout
->withoutErrors()             // suppress upstream error details in the exception thrown by send()
->withoutCircuitBreaker()     // send even while the circuit breaker for this service is open
->skipIf(bool $condition)     // when true, collect()/item() return an empty document instead of sending — see below
->send()                      // execute the request — throws ServiceRequestException on a non-2xx response
```

`with` and `merge` both add to the query string, but they resolve conflicts differently. `with` simply overwrites a key that's set twice; `merge` is meant for building up a query from several independent sources — a shared "sort" call site and a per-page filter, for example — and concatenates repeated comma-separated string values instead of replacing them:

```php
$client->service('pim')->get('/v1/products')
    ->with(['sort' => 'name'])
    ->merge(['sort' => 'price']); // -> sort=name,price
```

`withBody` and `fields` are the same relationship on the request body instead of the query string: `withBody` replaces the body outright, `fields` merges in — dropping any value that's `null`, so an optional field you didn't set is simply left out of the request instead of being sent as a literal `null`:

```php
$client->service('oms')->post('/v1/customers/store')
    ->fields([
        'type' => $type,
        'external_id' => $externalId, // omitted entirely when null
    ]);
```

### Uploading Files

`withMultipart` accepts a Guzzle-style array of parts and sends the request as `multipart/form-data` instead of JSON. Each part is an associative array with `name` and `contents`, plus `filename` for file parts:

```php
$client->service('pim')->post('/v1/products/42/images')
    ->withMultipart([
        ['name' => 'alt_text', 'contents' => 'Front view'],
        ['name' => 'image', 'contents' => fopen($path, 'r'), 'filename' => 'front.jpg'],
    ])
    ->send();
```

A request built with `withMultipart` ignores any body set via `withBody` — the two are mutually exclusive per request.

## Working with Responses

The `send` method returns a `ServiceResponse` instance that wraps the raw HTTP response with convenient accessors:

```php
$response->status();        // HTTP status code (int)
$response->ok();            // true if 2xx
$response->failed();        // true if 4xx or 5xx
$response->json();          // decoded JSON as array
$response->json('data.id'); // dot-notation access into the decoded body
$response->body();          // raw response body
$response->header('X-Total');
```

For JSON:API responses you typically won't reach for `json()` directly — see [JSON:API Responses](#jsonapi-responses) below for typed wrappers that handle relationships and pagination for you.

## Parallel Requests

Building a page out of several independent calls to other services is common enough that the client dispatches them concurrently rather than making you do it. To dispatch multiple requests at once, build each one without calling `send`, then hand them all to `parallel`:

```php
$responses = $client->parallel([
    'catalog'   => $client->service('pim')->get('/v1/categories/5'),
    'warehouse' => $client->service('pim')->get('/v1/warehouses/12'),
    'prices'    => $client->service('oms')->get('/v1/price-types/3'),
]);

$responses['catalog']->ok();
$responses['warehouse']->json();
```

All three requests are sent at the same time and the call blocks until every response has arrived — it takes as long as the slowest one, not the sum of all three. Array keys are preserved, so you can match each response back to the request that produced it.

Transport-level failures (a service that's unreachable, or times out) throw `ServiceUnavailableException` for the whole batch. Non-2xx responses, on the other hand, are returned as-is rather than thrown — `parallel` only raises on failures it can't hand back to you as a response, so you're expected to inspect status codes yourself when that matters.

Two shapes built on top of `parallel` — fetching a set of records by id (or any other value), and checking that a set of them all exist — come up often enough to have their own builder methods; see [Fetching in Bulk](#fetching-in-bulk) below.

## Fetching in Bulk

Reading many records from another service by id — or any other value it filters by — is common enough to deserve its own tools on top of `parallel`: `chunk` and `missing` both split your list into concurrent requests, so you're not building that scatter/gather by hand.

### Chunking

`chunk` fetches a resource filtered by an arbitrary set of values, batching them into concurrent requests so a list too large for one request doesn't have to be one:

```php
$client->service($name)->get($path)->chunk(
    array $values,
    callable $callback,
    int $chunkSize = 100,
    int $concurrency = 5,
): array;
```

```php
$products = $client->service('pim')->get('/v1/products')
    ->chunk($productIds, fn ($request, $ids) => $request->withBody([
        'filter' => ['id' => ['in' => $ids]],
        'page' => ['size' => count($ids)],
    ]));
```

`chunk` makes no assumption about how the target service expects a filter to be expressed — that's entirely up to `$callback`. It receives a clone of the request you built (the same service, method, path, and headers) along with the current chunk of values, and returns that clone configured however this particular endpoint needs — a `filter[id][in]` body like above, a comma-joined query string, or anything else. `chunk` splits `$values` into groups of `$chunkSize`, runs `$concurrency` of those chunk requests at a time through `parallel`, and concatenates the `data` from every response into one array. A failed chunk throws `ServiceRequestException` immediately, same as `send`.

### Checking Existence

`missing` answers a narrower question: which of these values don't exist on the other service? It sends one request per value — the path built by your callback — in parallel, and treats a `404` response as "missing":

```php
foreach ($client->service('pim')->missing($priceTypeIds, fn ($id) => "/v1/price_types/{$id}") as $id) {
    throw ValidationException::withMessages([
        'price_type_id' => ["Price type [$id] not found."],
    ]);
}
```

This is the usual way to validate a foreign key from another service — a `price_type_id`, a `warehouse_id`, a category slug — before persisting it locally.

## Retries

A service that's momentarily unreachable, or that returns a `5xx` while restarting, doesn't need to fail your request outright — it's often gone by the time you'd retry it yourself. Set `SERVICE_RETRIES_MAX` above `0` to have the client retry automatically, with exponential backoff between attempts:

```env
SERVICE_RETRIES_MAX=3         # retry attempts (0 disables retrying — the default)
SERVICE_RETRIES_DELAY=100     # delay before the first retry, in milliseconds
SERVICE_RETRIES_MULTIPLIER=2  # each subsequent delay is multiplied by this
```

With the values above, a request that keeps failing waits 100ms, then 200ms, then 400ms between attempts, before finally surfacing the failure to your code. Only connection failures and `5xx` responses are retried — a `4xx` means the request itself was rejected, and retrying it would just get rejected again, so those are returned immediately.

Retries are configured once for the whole client, not per request; there's no `->retries()` builder method, because a service that needs a different retry policy than its siblings is usually a sign it needs a different `SERVICE_RETRIES_MAX` in its own `.env`, not a per-call override.

## Circuit Breaker

Retries help with a blip. They don't help when a service is *actually* down — every request still pays the full timeout before giving up, which under load can tie up a lot of workers waiting on a service that has no chance of answering. The circuit breaker exists for that case: after enough consecutive failures, it stops sending requests to that service at all for a cooldown period, failing fast with `ServiceUnavailableException` instead.

It's disabled by default. Enable it by setting a failure threshold:

```env
SERVICE_CIRCUIT_BREAKER_THRESHOLD=5   # consecutive failures before the breaker opens (0 disables it)
SERVICE_CIRCUIT_BREAKER_TIMEOUT=30    # seconds the breaker stays open before allowing a probe request
SERVICE_CIRCUIT_BREAKER_WINDOW=60     # seconds a failure streak is remembered before resetting
```

The breaker tracks state per service, in the cache, so it's shared across every process talking to that service — one worker tripping it protects all the others. It moves through three states: **closed** (normal — every request goes through and is counted), **open** (every request fails immediately without being sent, for `SERVICE_CIRCUIT_BREAKER_TIMEOUT` seconds), and **half-open** (the cooldown has elapsed, so the next single request is allowed through as a probe; if it succeeds the breaker closes again, if it fails the breaker reopens for another full cooldown).

A handful of requests genuinely need to go through even while a breaker is open — a health check against the very service the breaker is protecting against, for instance. Use `withoutCircuitBreaker` to bypass it for one request:

```php
$client->service('oms')->get('/v1/health')->withoutCircuitBreaker()->send();
```

The current state of every service's breaker is visible on the gateway's health report — see [Health & Observability](gateway.md#health--observability).

## Distributed Tracing

Every request the client sends carries a [W3C Trace Context](https://www.w3.org/TR/trace-context/) `traceparent` header, so a single request that fans out across several services can be followed end-to-end in your tracing backend of choice. This is on by default; set `SERVICE_TRACING_ENABLED=false` to turn it off.

If the current inbound request already carries a `traceparent` (because it arrived through the gateway, or from another service further up the chain), the client reuses that trace ID and starts a new child span from it. Otherwise — for a request that originates here, such as one kicked off from a queued job or a console command — it starts a fresh trace. Either way this happens automatically; there's nothing to configure per request.

## JSON:API Responses

When the remote service returns a JSON:API document, you may use typed wrappers instead of working with the raw decoded array. Two methods on `PendingServiceRequest` produce them, sending the request and parsing the response in one step:

```php
$client->service('pim')->get('/v1/products')->collect(ProductItem::class);   // CollectionDocument<ProductItem>
$client->service('sfm')->get('/v1/sites/1')->item(SiteItem::class);          // ItemDocument<ProductItem>
```

Both wrappers handle relationship resolution, pagination metadata, and pass-through serialization for forwarding upstream documents to your own clients.

### Collection Documents

A `CollectionDocument` represents a JSON:API resource collection. You may access its data, metadata, and pagination details via dedicated methods:

```php
$document = $client->service('pim')
    ->get('/v1/products')
    ->with(['filter[catalog_id][in]' => '1,2'])
    ->collect(ProductItem::class);

$document->data();    // Collection<ProductItem>
$document->first();   // ProductItem|null
$document->meta();    // array
$document->total();   // ?int — from meta.total
```

To forward the document to your own client unchanged — the gateway pattern, but from application code — call `toResponse`:

```php
return $document->toResponse();
```

To enrich each item before forwarding, pass a closure that returns a JSON:API resource array. This is the common case for adding request-scoped data (like "is this in the current user's wishlist") that the upstream service has no way to know about:

```php
return $document->toResponse(function (ProductItem $item) {
    return array_merge($item->toArray(), [
        'attributes' => array_merge($item->attributes(), [
            'in_wishlist' => $this->wishlist->has($item->id),
        ]),
    ]);
});
```

If the response includes related resources under `included`, resolve them into typed collections with `withRelations`:

```php
$document->withRelations(['categories' => CategoryItem::class]);

foreach ($document->data() as $product) {
    $product->getRelation('categories');  // Collection<CategoryItem>
}
```

### Item Documents

For single-resource responses, use `ItemDocument` the same way:

```php
$document = $client->service('sfm')
    ->get("/v1/sites/{$id}")
    ->item(SiteItem::class);

$document->data();   // SiteItem
return $document->toResponse();
```

### Skipping The Request

Some calls to `collect` or `item` are only worth making some of the time — a search whose filters have already narrowed the result set to nothing, a lookup that only makes sense for an authenticated user. Rather than branch around the whole call and construct an empty document by hand for the other case, pass the condition to `skipIf`:

```php
$client->service('oms')->get('/v1/orders')
    ->merge(['filter' => ['customer.user_id' => ['eq' => $userId]]])
    ->skipIf($userId === null)
    ->collect(OrderItem::class);
```

When the condition is true, `collect` and `item` return an empty `CollectionDocument` or `ItemDocument` — same shape as a real response with no results — without the request ever being sent. `skipIf` has no effect on `send`, `json`, or `status`; it only short-circuits document building.

The empty document itself comes from `CollectionDocument::empty(ItemClass::class)` and `ItemDocument::empty(ItemClass::class)`, which you may call directly for a branch that never reaches a request builder at all — a guest with no possible orders, an id list that's empty before any filtering happens:

```php
if ($catalogIds === []) {
    return CollectionDocument::empty(ProductItem::class);
}
```

### Defining Custom Items

The `Item` base class exposes generic accessors for reading a JSON:API resource. To add domain-specific helpers on top of it, subclass it:

```php
use Jurager\Microservice\JsonApi\Item;

class ProductItem extends Item
{
    public function name(): string
    {
        return $this->attribute('name', '');
    }

    public function isActive(): bool
    {
        return (bool) $this->attribute('is_active');
    }
}
```

The base class provides the following accessors out of the box:

```php
$item->id;                          // resource id
$item->type;                        // resource type
$item->attribute($key, $default);   // single attribute, dot-notation access
$item->attributes();                // all attributes as an array
$item->relationIds($name);          // to-many linkage as int[]
$item->relationId($name);           // to-one linkage as ?int
$item->getRelation($name);          // resolved included collection
$item->getRelationOne($name);       // resolved single included item
$item->toArray();                   // serialize back to a JSON:API resource array
```

### Hooking Into Requests With `after`

`collect` and `item` accept a chain of hooks registered via `after`, which can rewrite the outgoing query string, the parsed response body, or both. Pass an object exposing a `prepare(array $query): array` method to adjust the query before the request is sent, a callable to transform the decoded body after it comes back, or both by implementing `__invoke` and `prepare` on the same object:

```php
class WithFullText
{
    public function __construct(private readonly string $term) {}

    public function prepare(array $query): array
    {
        return [...$query, 'filter[search]' => $this->term];
    }
}

$client->service('pim')->get('/v1/products')
    ->after(new WithFullText('desk lamp'))
    ->collect(ProductItem::class);
```

This is a niche escape hatch — reach for it when the same query-building or response-shaping logic needs to be shared across several call sites, rather than repeating it inline every time.

## Error Handling

The client raises two exception types depending on the nature of the failure:

```php
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Exceptions\ServiceRequestException;

try {
    $response = $client->service('oms')->get('/v1/orders')->send();
} catch (ServiceUnavailableException $e) {
    // Service is unreachable — DNS failure, connection refused, timeout, or an open circuit breaker.
} catch (ServiceRequestException $e) {
    $e->status; // Upstream HTTP status code
    $e->errors; // Upstream errors array; null when withoutErrors() was used
}
```

The package also auto-registers a JSON:API exception renderer for your own application. `ValidationException`, `ModelNotFoundException`, and the standard HTTP exceptions are all rendered as `application/vnd.api+json` without any configuration on your part, so a service that both calls other services and answers its own JSON:API requests gets consistent error shapes on both sides.
