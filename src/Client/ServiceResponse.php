<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use Illuminate\Http\Response;
use Jurager\Microservice\JsonApi\CollectionDocument;
use Jurager\Microservice\JsonApi\Item;
use Jurager\Microservice\JsonApi\ItemDocument;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class ServiceResponse
{
    protected ?array $decoded = null;

    public function __construct(
        protected readonly ResponseInterface $response,
    ) {}

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
        return ! $this->ok();
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
     * Forward the raw response body directly as a response.
     * Proxies all safe upstream headers, not just Content-Type.
     */
    public function passthrough(): Response
    {
        return response($this->body(), $this->status(), $this->passthroughHeaders());
    }

    private function passthroughHeaders(): array
    {
        $strip = ['transfer-encoding', 'connection'];

        $headers = [];

        foreach ($this->response->getHeaders() as $name => $values) {
            if (! in_array(strtolower($name), $strip, true)) {
                $headers[$name] = implode(', ', (array) $values);
            }
        }

        if (! isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/vnd.api+json';
        }

        return $headers;
    }

    /**
     * Parse a JSON:API collection response into a CollectionDocument.
     *
     * @template T of Item
     *
     * @param  class-string<T>  $itemClass
     * @return CollectionDocument<T>
     */
    public function collect(string $itemClass = Item::class): CollectionDocument
    {
        return new CollectionDocument($this->json() ?? [], $itemClass);
    }

    /**
     * Parse a JSON:API single-resource response into an ItemDocument.
     *
     * @template T of Item
     *
     * @param  class-string<T>  $itemClass
     * @return ItemDocument<T>
     */
    public function item(string $itemClass = Item::class): ItemDocument
    {
        return new ItemDocument($this->json() ?? [], $itemClass);
    }

    public function stream(): StreamInterface
    {
        return $this->response->getBody();
    }

    public function toPsrResponse(): ResponseInterface
    {
        return $this->response;
    }
}
