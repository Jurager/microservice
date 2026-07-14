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
    protected string $method = 'GET';

    protected string $path = '/';

    protected array $headers = [];

    protected array $query = [];

    protected ?array $body = null;

    protected ?array $multipart = null;

    protected ?int $timeout = null;

    protected bool $exposeError = true;

    protected bool $bypassCircuitBreaker = false;

    public function __construct(
        protected readonly ServiceClient $client,
        protected readonly string $service,
    ) {
    }

    public function get(string $path): static
    {
        return $this->withMethod('GET', $path);
    }

    public function post(string $path, ?array $body = null): static
    {
        return $this->withMethod('POST', $path, $body);
    }

    public function put(string $path, ?array $body = null): static
    {
        return $this->withMethod('PUT', $path, $body);
    }

    public function patch(string $path, ?array $body = null): static
    {
        return $this->withMethod('PATCH', $path, $body);
    }

    public function delete(string $path): static
    {
        return $this->withMethod('DELETE', $path);
    }

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

    public function headers(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    public function withHeaders(array $headers): static
    {
        return $this->headers($headers);
    }

    public function with(array $query): static
    {
        $this->query = array_merge($this->query, $this->stripEmpty($query));

        return $this;
    }

    private function stripEmpty(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $filtered = $this->stripEmpty($value);
                if ($filtered !== []) {
                    $result[$key] = $filtered;
                }
            } elseif ($value !== '' && $value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function merge(array $query): static
    {
        $query = $this->stripEmpty($query);

        foreach ($query as $key => $value) {
            if (isset($this->query[$key]) && is_string($value) && is_string($this->query[$key])) {
                $this->query[$key] = implode(',', array_unique(array_filter([
                    ...explode(',', $this->query[$key]),
                    ...explode(',', $value),
                ])));
            } elseif (isset($this->query[$key]) && is_array($value) && is_array($this->query[$key])) {
                $this->query[$key] = array_replace_recursive($this->query[$key], $value);
            } else {
                $this->query[$key] = $value;
            }
        }

        return $this;
    }

    public function withBody(array $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function withMultipart(array $multipart): static
    {
        $this->multipart = $multipart;

        return $this;
    }

    public function withoutErrors(): static
    {
        $this->exposeError = false;

        return $this;
    }

    public function withoutCircuitBreaker(): static
    {
        $this->bypassCircuitBreaker = true;

        return $this;
    }

    public function getBypassCircuitBreaker(): bool
    {
        return $this->bypassCircuitBreaker;
    }

    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    private array $afterHooks = [];

    /**
     * @param  callable(array): array|object  $processor
     */
    public function after(callable|object $processor): static
    {
        $this->afterHooks[] = $processor;

        return $this;
    }

    /**
     * @throws ServiceUnavailableException
     * @throws ServiceRequestException
     */
    public function send(): ServiceResponse
    {
        $response = $this->client->send($this);

        if ($response->failed()) {
            throw new ServiceRequestException($response->status(), errors: $this->exposeError ? ($response->json('errors') ?: null) : null);
        }

        return $response;
    }

    /** @param class-string<T> $itemClass @template T of Item @return CollectionDocument<T> */
    public function collect(string $itemClass = Item::class): CollectionDocument
    {
        $this->runPrepare();

        $body = $this->json();

        if ($this->afterHooks !== []) {
            $body = $this->applyAfterHooks($body);
        }

        return new CollectionDocument($body, $itemClass);
    }

    /** @param class-string<T> $itemClass @template T of Item @return ItemDocument<T> */
    public function item(string $itemClass = Item::class): ItemDocument
    {
        $this->runPrepare();

        $body = $this->json();

        if ($this->afterHooks !== []) {
            $body = $this->applyAfterHooks($body);
        }

        return new ItemDocument($body, $itemClass);
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        return $this->send()->json($key, $default);
    }

    public function status(): int
    {
        return $this->send()->status();
    }

    private function runPrepare(): void
    {
        foreach ($this->afterHooks as $hook) {
            if (is_object($hook) && method_exists($hook, 'prepare')) {
                $this->query = $hook->prepare($this->query);
            }
        }
    }

    private function applyAfterHooks(array $body): array
    {
        foreach ($this->afterHooks as $hook) {
            $body = $hook($body);
        }

        return $body;
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

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getQuery(): array
    {
        return $this->query;
    }

    public function getBody(): ?array
    {
        return $this->body;
    }

    public function getMultipart(): ?array
    {
        return $this->multipart;
    }

    public function getTimeout(): ?int
    {
        return $this->timeout;
    }
}
