<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

class PendingServiceRequest
{
    protected string $method = 'GET';

    protected string $path = '/';

    protected array $headers = [];

    protected array $query = [];

    protected ?array $body = null;

    protected ?int $timeout = null;

    protected ?int $retries = null;

    public function __construct(
        protected readonly ServiceClient $client,
        protected readonly string $service,
    ) {}

    public function get(string $path): static { return $this->withMethod('GET', $path); }

    public function post(string $path, ?array $body = null): static { return $this->withMethod('POST', $path, $body); }

    public function put(string $path, ?array $body = null): static { return $this->withMethod('PUT', $path, $body); }

    public function patch(string $path, ?array $body = null): static { return $this->withMethod('PATCH', $path, $body); }

    public function delete(string $path): static { return $this->withMethod('DELETE', $path); }

    public function withMethod(string $method, string $path, ?array $body = null): static
    {
        $this->method = $method;
        $this->path = $path;
        $this->body = $body;

        return $this;
    }

    public function withHeaders(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    public function withQuery(array $query): static
    {
        $this->query = array_merge($this->query, $query);

        return $this;
    }

    public function withBody(array $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    public function retries(int $retries): static
    {
        $this->retries = $retries;

        return $this;
    }

    /**
     * @throws \Jurager\Microservice\Exceptions\ServiceUnavailableException
     */
    public function send(): ServiceResponse
    {
        return $this->client->send($this);
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

    public function getTimeout(): ?int
    {
        return $this->timeout;
    }

    public function getRetries(): ?int
    {
        return $this->retries;
    }
}
