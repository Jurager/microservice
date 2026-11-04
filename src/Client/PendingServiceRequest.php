<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use Illuminate\Support\Str;
use JsonException;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\JsonApi\CollectionDocument;
use Jurager\Microservice\JsonApi\Item;
use Jurager\Microservice\JsonApi\ItemDocument;
use LogicException;

/**
 * Build an HTTP request to another service.
 */
class PendingServiceRequest
{
    /**
     * Methods that change nothing on the peer.
     */
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    protected string $method = 'GET';

    protected string $path = '/';

    /** @var array<string, string> */
    protected array $headers = [];

    /** @var array<string, mixed> */
    protected array $query = [];

    /**
     * JSON body, as an array still open to mergeJson().
     */
    protected ?array $json = null;

    /**
     * Body sent as given, with its content type — what withBody() sets.
     */
    protected ?string $content = null;

    protected string $contentType = 'application/json';

    /** @var list<array<string, mixed>>|null Guzzle-style multipart parts. */
    protected ?array $multipart = null;

    /**
     * Seconds the request may take; the endpoint's default when null.
     */
    protected ?int $timeout = null;

    /**
     * Include the upstream error objects in the exception thrown by send().
     */
    protected bool $exposeErrors = true;

    /**
     * Throw ServiceRequestException for a response that is not 2xx.
     */
    protected bool $throwOnFailure = true;

    protected bool $bypassCircuitBreaker = false;

    /**
     * The peer deduplicates this write by its X-Request-Id, so it may be retried.
     */
    protected bool $idempotent = false;

    /**
     * Answer collect() and item() with an empty document instead of sending.
     */
    protected bool $skipped = false;

    /** @var list<callable(array): array> Applied to the query before sending. */
    protected array $queryCallbacks = [];

    /** @var list<callable(array): array> Applied to the decoded body before a document is built from it. */
    protected array $bodyCallbacks = [];

    public function __construct(
        protected readonly ServiceClient $client,
        protected readonly string $service,
    ) {
    }

    public function get(string $path): static
    {
        return $this->withMethod('GET', $path);
    }

    public function post(string $path, ?array $json = null): static
    {
        return $this->withMethod('POST', $path, $json);
    }

    public function put(string $path, ?array $json = null): static
    {
        return $this->withMethod('PUT', $path, $json);
    }

    public function patch(string $path, ?array $json = null): static
    {
        return $this->withMethod('PATCH', $path, $json);
    }

    public function delete(string $path): static
    {
        return $this->withMethod('DELETE', $path);
    }

    /**
     * Set the method, the path and, optionally, the JSON body. A write gets an X-Request-Id of its own.
     */
    public function withMethod(string $method, string $path, ?array $json = null): static
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->json = $json;
        $this->content = null;

        if ($this->isSafe()) {
            unset($this->headers['X-Request-Id']);
        } else {
            $this->headers['X-Request-Id'] ??= (string) Str::uuid();
        }

        return $this;
    }

    /**
     * Whether the request is side-effect free (GET, HEAD, OPTIONS).
     */
    public function isSafe(): bool
    {
        return in_array($this->method, self::SAFE_METHODS, true);
    }

    /** @param  array<string, string>  $headers */
    public function withHeaders(array $headers): static
    {
        $this->headers = [...$this->headers, ...$headers];

        return $this;
    }

    /**
     * Merge query string parameters, dropping null values.
     */
    public function withQueryParameters(array $query): static
    {
        $this->query = [...$this->query, ...self::withoutNulls($query)];

        return $this;
    }

    /**
     * Merge query parameters, combining arrays and comma-separated values.
     */
    public function mergeQueryParameters(array $query): static
    {
        foreach (self::withoutNulls($query) as $key => $value) {
            $existing = $this->query[$key] ?? null;

            $this->query[$key] = match (true) {
                is_string($value) && is_string($existing) => implode(',', array_unique(array_filter([...explode(',', $existing), ...explode(',', $value)]))),
                is_array($value) && is_array($existing) => array_replace_recursive($existing, $value),
                default => $value,
            };
        }

        return $this;
    }

    /**
     * Send the given array as the JSON body.
     */
    public function withJson(array $json): static
    {
        $this->json = $json;
        $this->content = null;

        return $this;
    }

    /**
     * Merge fields into the JSON body, dropping null values.
     */
    public function mergeJson(array $fields): static
    {
        return $this->withJson([...$this->json ?? [], ...self::withoutNulls($fields)]);
    }

    /**
     * Send a body exactly as given — what the gateway forwards.
     */
    public function withBody(string $content, string $contentType = 'application/json'): static
    {
        $this->content = $content;
        $this->contentType = $contentType;
        $this->json = null;

        return $this;
    }

    /** @param  list<array<string, mixed>>  $multipart  Guzzle-style parts: name, contents and, for a file, filename. */
    public function withMultipart(array $multipart): static
    {
        $this->multipart = $multipart;

        return $this;
    }

    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Leave the upstream error objects out of the exception send() throws.
     */
    public function withoutErrors(): static
    {
        $this->exposeErrors = false;

        return $this;
    }

    /**
     * Return a response that is not 2xx instead of throwing.
     */
    public function withoutThrowing(): static
    {
        $this->throwOnFailure = false;

        return $this;
    }

    /**
     * Send even while the circuit breaker of the service is open.
     */
    public function withoutCircuitBreaker(): static
    {
        $this->bypassCircuitBreaker = true;

        return $this;
    }

    /**
     * The peer deduplicates this write by its X-Request-Id, so a failed attempt may be repeated.
     */
    public function idempotent(): static
    {
        $this->idempotent = true;

        return $this;
    }

    /**
     * When true, collect() and item() return an empty document without sending the request.
     */
    public function skipIf(bool $condition): static
    {
        $this->skipped = $this->skipped || $condition;

        return $this;
    }

    /**
     * Change the query string before the request is sent — shareable as an invokable object.
     *
     * @param  callable(array): array  $callback
     */
    public function mapQuery(callable $callback): static
    {
        $this->queryCallbacks[] = $callback;

        return $this;
    }

    /**
     * Change the decoded body before collect() or item() builds the document from it.
     *
     * @param  callable(array): array  $callback
     */
    public function mapBody(callable $callback): static
    {
        $this->bodyCallbacks[] = $callback;

        return $this;
    }

    /**
     * Send the request.
     *
     * @throws ServiceUnavailableException
     * @throws ServiceRequestException unless withoutThrowing() was called
     */
    public function send(): ServiceResponse
    {
        if ($this->skipped) {
            throw new LogicException('A request skipped with skipIf() has no response; use collect() or item() to receive an empty document.');
        }

        $response = $this->client->send($this);

        if ($this->throwOnFailure && ! $response->successful()) {
            throw $this->failure($response);
        }

        return $response;
    }

    /**
     * Send the request and read the response as a JSON:API collection document.
     *
     * @template T of Item
     *
     * @param  class-string<T>  $itemClass
     * @return CollectionDocument<T>
     */
    public function collect(string $itemClass = Item::class): CollectionDocument
    {
        return $this->skipped
            ? CollectionDocument::empty($itemClass)
            : new CollectionDocument($this->mappedBody(), $itemClass);
    }

    /**
     * Send the request and read the response as a JSON:API single-resource document.
     *
     * @template T of Item
     *
     * @param  class-string<T>  $itemClass
     * @return ItemDocument<T>
     */
    public function item(string $itemClass = Item::class): ItemDocument
    {
        return $this->skipped
            ? ItemDocument::empty($itemClass)
            : new ItemDocument($this->mappedBody(), $itemClass);
    }

    /**
     * Fetch a resource by an arbitrary set of values, in chunks sent concurrently.
     *
     * @param  array<int, mixed>  $values
     * @param  callable(static, array<int, mixed>): static  $callback  Configures one chunk's request from a copy of this one.
     * @return array<int, array<string, mixed>> The `data` entries of every chunk's response.
     *
     * @throws ServiceUnavailableException
     * @throws ServiceRequestException when any chunk's response is not 2xx
     */
    public function chunk(array $values, callable $callback, int $chunkSize = 100, int $concurrency = 5): array
    {
        if ($values === []) {
            return [];
        }

        $requests = [];

        foreach (array_chunk(array_values($values), max(1, $chunkSize)) as $chunk) {
            $request = clone $this;

            // Every chunk is a write of its own — sharing an id would replay the first chunk's response.
            if (! $request->isSafe()) {
                $request->headers['X-Request-Id'] = (string) Str::uuid();
            }

            $requests[] = $callback($request, $chunk);
        }

        $results = [];

        foreach ($this->client->parallel($requests, $concurrency) as $key => $response) {
            if (! $response->successful()) {
                throw $requests[$key]->failure($response);
            }

            array_push($results, ...($response->json('data') ?? []));
        }

        return $results;
    }

    /**
     * Get values that return 404 from the paths built by the callback.
     *
     * @param  array<int|string, mixed>  $values
     * @param  callable(mixed): string  $path  Builds the path checking one value.
     * @return list<mixed> The values answered with 404.
     *
     * @throws ServiceUnavailableException
     * @throws ServiceRequestException when a check fails for any reason other than 404 — existence is then unknown
     */
    public function missing(array $values, callable $path): array
    {
        if ($values === []) {
            return [];
        }

        $requests = array_map(fn (mixed $value): static => (clone $this)->get($path($value)), $values);
        $missing = [];

        foreach ($this->client->parallel($requests) as $key => $response) {
            if ($response->status() === 404) {
                $missing[] = $values[$key];
            } elseif (! $response->successful()) {
                throw $requests[$key]->failure($response);
            }
        }

        return $missing;
    }

    /**
     * Whether the response may be reused for the rest of the current request or job.
     */
    public function shouldMemoize(): bool
    {
        return in_array($this->method, ['GET', 'HEAD'], true) && $this->multipart === null;
    }

    /**
     * Whether a failed attempt may be repeated without applying the request twice.
     */
    public function isRepeatable(): bool
    {
        return $this->isSafe() || $this->idempotent;
    }

    public function getService(): string
    {
        return $this->service;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * The query string parameters, after mapQuery() callbacks.
     */
    public function getQuery(): array
    {
        return array_reduce($this->queryCallbacks, static fn (array $query, callable $callback): array => $callback($query), $this->query);
    }

    /**
     * The query string exactly as it is sent and signed.
     */
    public function getQueryString(): string
    {
        return http_build_query($this->getQuery(), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The request target: the path followed by its query string, if any.
     */
    public function target(): string
    {
        $query = $this->getQueryString();

        return $query === '' ? $this->path : $this->path.'?'.$query;
    }

    public function getJson(): ?array
    {
        return $this->json;
    }

    /**
     * The body as sent: the given content, or the JSON body encoded.
     *
     * @throws JsonException
     */
    public function getBody(): ?string
    {
        return $this->content ?? ($this->json !== null
            ? json_encode($this->json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : null);
    }

    public function getContentType(): string
    {
        return $this->content !== null ? $this->contentType : 'application/json';
    }

    public function getMultipart(): ?array
    {
        return $this->multipart;
    }

    public function getTimeout(): ?int
    {
        return $this->timeout;
    }

    public function bypassesCircuitBreaker(): bool
    {
        return $this->bypassCircuitBreaker;
    }

    /**
     * The exception answering a response that is not 2xx.
     */
    protected function failure(ServiceResponse $response): ServiceRequestException
    {
        $errors = $this->exposeErrors ? $response->json('errors') : null;

        return new ServiceRequestException(
            $response->status(),
            errors: is_array($errors) && $errors !== [] ? $errors : null,
            service: $this->service,
        );
    }

    /**
     * The decoded body of the response, passed through the mapBody() callbacks.
     */
    private function mappedBody(): array
    {
        return array_reduce($this->bodyCallbacks, static fn (array $body, callable $callback): array => $callback($body), $this->send()->document());
    }

    /** @return array<array-key, mixed> */
    private static function withoutNulls(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::withoutNulls($value);
            } elseif ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
