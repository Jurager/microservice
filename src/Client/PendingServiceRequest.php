<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use Illuminate\Support\Str;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\JsonApi\CollectionDocument;
use Jurager\Microservice\JsonApi\Item;
use Jurager\Microservice\JsonApi\ItemDocument;

class PendingServiceRequest
{
    /**
     * HTTP request method.
     */
    protected string $method = 'GET';

    /**
     * Request path.
     */
    protected string $path = '/';

    /**
     * Request headers.
     *
     * @var array<string, string>
     */
    protected array $headers = [];

    /**
     * URL query string parameters.
     *
     * @var array<string, mixed>
     */
    protected array $query = [];

    /**
     * JSON request body.
     */
    protected ?array $body = null;

    /**
     * Multipart form data payload.
     */
    protected ?array $multipart = null;

    /**
     * Request timeout in seconds.
     */
    protected ?int $timeout = null;

    /**
     * Whether to expose upstream errors in exceptions.
     */
    protected bool $exposeError = true;

    /**
     * Whether to bypass the circuit breaker.
     */
    protected bool $bypassCircuitBreaker = false;

    /**
     * Registered response/query processing hooks.
     *
     * @var array<int, callable|object>
     */
    private array $afterHooks = [];

    public function __construct(
        protected readonly ServiceClient $client,
        protected readonly string $service,
    ) {
    }

    /** Set request method to GET. */
    public function get(string $path): static
    {
        return $this->withMethod('GET', $path);
    }

    /** Set request method to POST. */
    public function post(string $path, ?array $body = null): static
    {
        return $this->withMethod('POST', $path, $body);
    }

    /** Set request method to PUT. */
    public function put(string $path, ?array $body = null): static
    {
        return $this->withMethod('PUT', $path, $body);
    }

    /** Set request method to PATCH. */
    public function patch(string $path, ?array $body = null): static
    {
        return $this->withMethod('PATCH', $path, $body);
    }

    /** Set request method to DELETE. */
    public function delete(string $path): static
    {
        return $this->withMethod('DELETE', $path);
    }

    /** Set request HTTP method and path. */
    public function withMethod(string $method, string $path, ?array $body = null): static
    {
        $this->method = $method;
        $this->path = $path;
        $this->body = $body;

        if (! in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $this->headers['X-Request-Id'] ??= (string) Str::uuid();
        }

        return $this;
    }

    /** Merge additional headers into the request. */
    public function headers(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /** Alias for headers(). */
    public function withHeaders(array $headers): static
    {
        return $this->headers($headers);
    }

    /** Merge query parameters, ignoring nulls. */
    public function with(array $query): static
    {
        $this->query = array_merge($this->query, $this->stripEmpty($query));

        return $this;
    }

    /** Deep merge query parameters, concatenating comma-separated strings and replacing arrays. */
    public function merge(array $query): static
    {
        foreach ($this->stripEmpty($query) as $key => $value) {
            $existing = $this->query[$key] ?? null;

            if ($existing === null) {
                $this->query[$key] = $value;

                continue;
            }

            if (is_string($value) && is_string($existing)) {
                $merged = [...explode(',', $existing), ...explode(',', $value)];
                $this->query[$key] = implode(',', array_unique(array_filter($merged)));

                continue;
            }

            if (is_array($value) && is_array($existing)) {
                $this->query[$key] = array_replace_recursive($existing, $value);

                continue;
            }

            $this->query[$key] = $value;
        }

        return $this;
    }

    /** Remove null values from array recursively. */
    private function stripEmpty(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->stripEmpty($value);
            } elseif ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** Set JSON request body. */
    public function withBody(array $body): static
    {
        $this->body = $body;

        return $this;
    }

    /** Set multipart form payload. */
    public function withMultipart(array $multipart): static
    {
        $this->multipart = $multipart;

        return $this;
    }

    /** Prevent upstream errors from being exposed in exceptions. */
    public function withoutErrors(): static
    {
        $this->exposeError = false;

        return $this;
    }

    /** Force request to bypass the circuit breaker. */
    public function withoutCircuitBreaker(): static
    {
        $this->bypassCircuitBreaker = true;

        return $this;
    }

    /** Check if circuit breaker bypass is active. */
    public function getBypassCircuitBreaker(): bool
    {
        return $this->bypassCircuitBreaker;
    }

    /** Set request timeout in seconds. */
    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Register a hook to process the query before sending or the body after receiving.
     *
     * @param  callable(array): (array|object)|object  $processor
     */
    public function after(callable|object $processor): static
    {
        $this->afterHooks[] = $processor;

        return $this;
    }

    /**
     * Execute the request.
     *
     * @throws ServiceUnavailableException
     * @throws ServiceRequestException
     */
    public function send(): ServiceResponse
    {
        $response = $this->client->send($this);

        if ($response->failed()) {
            $errors = $this->exposeError ? ($response->json('errors') ?: null) : null;

            throw new ServiceRequestException($response->status(), errors: $errors);
        }

        return $response;
    }

    /**
     * Parse response into a JSON:API Collection Document.
     *
     * @template T of Item
     *
     * @param  class-string<T>  $itemClass
     * @return CollectionDocument<T>
     */
    public function collect(string $itemClass = Item::class): CollectionDocument
    {
        $this->runPrepare();

        $body = $this->applyAfterHooks($this->json());

        return new CollectionDocument($body, $itemClass);
    }

    /**
     * Parse response into a JSON:API Item Document.
     *
     * @template T of Item
     *
     * @param  class-string<T>  $itemClass
     * @return ItemDocument<T>
     */
    public function item(string $itemClass = Item::class): ItemDocument
    {
        $this->runPrepare();

        $body = $this->applyAfterHooks($this->json());

        return new ItemDocument($body, $itemClass);
    }

    /** Get JSON response. */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        return $this->send()->json($key, $default);
    }

    /** Get response HTTP status code. */
    public function status(): int
    {
        return $this->send()->status();
    }

    /** Execute preparation hooks to modify query parameters before sending. */
    private function runPrepare(): void
    {
        foreach ($this->afterHooks as $hook) {
            if (is_object($hook) && method_exists($hook, 'prepare')) {
                $this->query = $hook->prepare($this->query);
            }
        }
    }

    /** Execute after hooks to transform response body. */
    private function applyAfterHooks(array $body): array
    {
        if (empty($this->afterHooks)) {
            return $body;
        }

        foreach ($this->afterHooks as $hook) {
            if (is_callable($hook)) {
                $body = $hook($body);
            }
        }

        return $body;
    }

    /** Get target service name. */
    public function getService(): string
    {
        return $this->service;
    }

    /** Get request HTTP method. */
    public function getMethod(): string
    {
        return $this->method;
    }

    /** Get request path. */
    public function getPath(): string
    {
        return $this->path;
    }

    /** Get request headers. */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** Get query parameters. */
    public function getQuery(): array
    {
        return $this->query;
    }

    /** Get JSON request body. */
    public function getBody(): ?array
    {
        return $this->body;
    }

    /** Get multipart request payload. */
    public function getMultipart(): ?array
    {
        return $this->multipart;
    }

    /** Get request timeout. */
    public function getTimeout(): ?int
    {
        return $this->timeout;
    }
}
