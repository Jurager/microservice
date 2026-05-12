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

    public function withHeaders(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
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
        foreach ($query as $key => $value) {
            if (isset($this->query[$key]) && is_string($value) && is_string($this->query[$key])) {
                $this->query[$key] = implode(',', array_unique(array_filter([
                    ...explode(',', $this->query[$key]),
                    ...explode(',', $value),
                ])));
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

    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * @throws ServiceUnavailableException
     * @throws ServiceRequestException
     */
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
        return $this->send()->collect($itemClass);
    }

    /** @param class-string<T> $itemClass @template T of Item @return ItemDocument<T> */
    public function item(string $itemClass = Item::class): ItemDocument
    {
        return $this->send()->item($itemClass);
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        return $this->send()->json($key, $default);
    }

    public function status(): int
    {
        return $this->send()->status();
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
