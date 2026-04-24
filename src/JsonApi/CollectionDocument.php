<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Represents a JSON:API collection response.
 *
 * @template T of Item
 */
class CollectionDocument
{
    /** @var Collection<int, T> */
    private Collection $items;

    private array $meta;
    private array $links;
    private Includes $included;

    /** @param  class-string<T>  $itemClass */
    public function __construct(array $body, private readonly string $itemClass = Item::class)
    {
        $this->meta     = $body['meta'] ?? [];
        $this->links    = $body['links'] ?? [];
        $this->included = new Includes($body['included'] ?? []);

        $this->items = Collection::make($body['data'] ?? [])
            ->map(fn (array $resource) => Item::from($resource, $this->itemClass));
    }

    /** @return Collection<int, T> */
    public function data(): Collection
    {
        return $this->items;
    }

    /** @return T|null */
    public function first(): ?Item
    {
        return $this->items->first();
    }

    public function meta(): array
    {
        return $this->meta;
    }

    public function links(): array
    {
        return $this->links;
    }

    public function total(): ?int
    {
        return isset($this->meta['total']) ? (int) $this->meta['total'] : null;
    }

    public function included(): Includes
    {
        return $this->included;
    }

    /**
     * Resolve included relationships and attach them to each item.
     *
     * @param  array<string, class-string<Item>>  $map
     */
    public function withRelations(array $map): static
    {
        $this->items->each(fn (Item $item) => $this->included->attachRelations($item, $map));

        return $this;
    }

    public function rawIncluded(): array
    {
        return $this->included->raw();
    }

    /**
     * Serialize to a JSON:API JsonResponse.
     *
     * Without a callback: items are serialized as-is.
     * With a callback: each item is passed through the callable,
     * which must return a JSON:API resource array.
     *
     * @param  callable(T): array|null  $transform
     */
    public function toResponse(?callable $transform = null): JsonResponse
    {
        $data = $this->items->map(
            fn (Item $item) => $transform ? $transform($item) : $item->toArray()
        )->values()->all();

        $body = ['data' => $data];

        if ($raw = $this->included->raw()) {
            $body['included'] = $raw;
        }
        if ($this->links) {
            $body['links'] = $this->resolveLinks();
        }
        if ($this->meta) {
            $body['meta'] = $this->meta;
        }

        return new JsonResponse($body, 200, ['Content-Type' => 'application/vnd.api+json']);
    }

    private function resolveLinks(): array
    {
        /** @var Request|null $request */
        $request = request();

        if (! $request instanceof Request) {
            return $this->links;
        }

        $currentPage = (int) ($this->meta['current_page'] ?? $request->query('page', 1));
        $lastPage    = (int) ($this->meta['last_page'] ?? 1);
        $pageUrl     = fn (int $page) => $request->fullUrlWithQuery(['page' => $page]);

        return [
            'first' => $pageUrl(1),
            'last'  => $pageUrl($lastPage),
            'prev'  => $currentPage > 1        ? $pageUrl($currentPage - 1) : null,
            'next'  => $currentPage < $lastPage ? $pageUrl($currentPage + 1) : null,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    public function count(): int
    {
        return $this->items->count();
    }

    public function each(callable $callback): static
    {
        $this->items->each($callback);

        return $this;
    }

    public function map(callable $callback): Collection
    {
        return $this->items->map($callback);
    }
}
