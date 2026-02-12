<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use Jurager\Microservice\Exceptions\ServiceRequestException;
use Psr\Http\Message\ResponseInterface;

class ServiceResponse
{
    protected ?array $decoded = null;

    public function __construct(
        protected readonly ResponseInterface $response,
    ) {
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function ok(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    public function failed(): bool
    {
        return !$this->ok();
    }

    public function body(): string
    {
        $body = $this->response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return (string) $body;
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->decoded === null) {
            try {
                $decoded = json_decode($this->body(), true, 512, JSON_THROW_ON_ERROR);
                $this->decoded = is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                $this->decoded = [];
            }
        }

        if ($key === null) {
            return $this->decoded;
        }

        return data_get($this->decoded, $key, $default);
    }

    public function header(string $name): ?string
    {
        return $this->response->hasHeader($name)
            ? $this->response->getHeaderLine($name)
            : null;
    }

    /**
     * @return array<string, string[]>
     */
    public function headers(): array
    {
        return $this->response->getHeaders();
    }

    /**
     * Throw an exception if the response indicates a failure.
     */
    public function throw(): static
    {
        if ($this->failed()) {
            throw new ServiceRequestException($this);
        }

        return $this;
    }

    public function toPsrResponse(): ResponseInterface
    {
        return $this->response;
    }
}
