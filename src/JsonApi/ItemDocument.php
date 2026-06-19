<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Represents a JSON:API single-resource response.
 *
 * @template T of Item
 */
class ItemDocument implements Responsable
{
    /** @var T */
    private Item $item;

    private array $meta;

    private array $links;

    private Includes $included;

    /** @param  class-string<T>  $itemClass */
    public function __construct(array $body, private readonly string $itemClass = Item::class)
    {
        $this->meta = $body['meta'] ?? [];
        $this->links = $body['links'] ?? [];
        $this->included = new Includes($body['included'] ?? []);
        $this->item = Item::from($body['data'] ?? [], $this->itemClass);

        $this->included->autoAttach($this->item);
    }

    /** @return T */
    public function data(): Item
    {
        return $this->item;
    }

    public function meta(): array
    {
        return $this->meta;
    }

    public function links(): array
    {
        return $this->links;
    }

    public function included(): Includes
    {
        return $this->included;
    }

    /**
     * Resolve included relationships and attach them to the item.
     *
     * @param  array<string, class-string<Item>>  $map
     */
    public function withRelations(array $map): static
    {
        $this->included->attachRelations($this->item, $map);

        return $this;
    }

    public function filterIncluded(callable $filter): static
    {
        $this->included->filter($filter);

        $this->withoutOrphans();

        return $this;
    }

    /**
     * Remove relationship links that reference resources no longer in `included`.
     */
    public function withoutOrphans(): static
    {
        $this->item->withoutOrphanLinks($this->included);

        return $this;
    }

    public function rawIncluded(): array
    {
        return $this->included->raw();
    }

    /**
     * Responsable: apply any registered included filter, then serialize.
     */
    public function toResponse($request): JsonResponse
    {
        if ($request instanceof Request && ! $this->included->isEmpty()) {
            $filter = $request->attributes->get(Includes::class);
            if ($filter) {
                $this->filterIncluded($filter);
            }
        }

        return $this->toJsonResponse();
    }

    /**
     * Serialize to a JSON:API JsonResponse.
     *
     * Without a callback: item is serialized as-is.
     * With a callback: item is passed through the callable,
     * which must return a JSON:API resource array.
     *
     * @param  callable(T): array|null  $transform
     */
    public function toJsonResponse(?callable $transform = null): JsonResponse
    {
        $data = $transform ? $transform($this->item) : $this->item->toArray();

        $body = ['data' => $data];

        if ($raw = $this->included->raw()) {
            $body['included'] = $raw;
        }
        if ($this->links) {
            $body['links'] = $this->links;
        }
        if ($this->meta) {
            $body['meta'] = $this->meta;
        }

        return new JsonResponse($body, 200, ['Content-Type' => 'application/vnd.api+json']);
    }
}
