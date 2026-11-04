<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Response as HttpResponse;
use Jurager\Microservice\JsonApi\CollectionDocument;
use Jurager\Microservice\JsonApi\Item;
use Jurager\Microservice\JsonApi\ItemDocument;

/**
 * An HTTP client response with JSON:API document helpers.
 */
class ServiceResponse extends Response
{
    /**
     * Upstream headers describing the connection rather than the response.
     */
    private const array HOP_BY_HOP = ['transfer-encoding', 'connection', 'keep-alive'];

    /**
     * Wrap a response of Laravel's HTTP client.
     */
    public static function from(Response $response): static
    {
        $wrapped = new static($response->toPsrResponse());
        $wrapped->transferStats = $response->transferStats;

        return $wrapped;
    }

    /**
     * The body as a JSON:API collection document.
     *
     * @template T of Item
     *
     * @param  class-string<T>  $itemClass
     * @return CollectionDocument<T>
     */
    public function collection(string $itemClass = Item::class): CollectionDocument
    {
        return new CollectionDocument($this->document(), $itemClass);
    }

    /**
     * The body as a JSON:API single-resource document.
     *
     * @template T of Item
     *
     * @param  class-string<T>  $itemClass
     * @return ItemDocument<T>
     */
    public function item(string $itemClass = Item::class): ItemDocument
    {
        return new ItemDocument($this->document(), $itemClass);
    }

    /**
     * The decoded body, or an empty document when the body is not a JSON object.
     */
    public function document(): array
    {
        $decoded = $this->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The response as one of this application's, with its status, body and headers.
     */
    public function passthrough(): HttpResponse
    {
        $headers = [];

        foreach ($this->headers() as $name => $values) {
            if (! in_array(strtolower($name), self::HOP_BY_HOP, true)) {
                $headers[$name] = implode(', ', $values);
            }
        }

        $headers['Content-Type'] ??= 'application/vnd.api+json';

        return response($this->body(), $this->status(), $headers);
    }
}
