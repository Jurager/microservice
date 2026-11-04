<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Represents a JSON:API collection response.
 *
 * @template T of Item
 *
 * @extends Document<T>
 */
class CollectionDocument extends Document
{
    /** @var Collection<int, T> */
    private Collection $items;

    /** @param  class-string<T>  $itemClass */
    public function __construct(array $body, string $itemClass = Item::class)
    {
        parent::__construct($body, $itemClass);

        $this->items = Collection::make($body['data'] ?? [])
            ->map(fn (array $resource) => Item::from($resource, $this->itemClass));

        $this->included->registerPrimary($this->items->all());
        $this->items->each(fn (Item $item) => $this->included->autoAttach($item));
    }

    /**
     * Create an empty collection document.
     *
     * @template TItem of Item
     *
     * @param  class-string<TItem>  $itemClass
     * @return static<TItem>
     */
    public static function empty(string $itemClass = Item::class): static
    {
        return new static(['data' => []], $itemClass);
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

    public function total(): ?int
    {
        return isset($this->meta['total']) ? (int) $this->meta['total'] : null;
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

    protected function roots(): array
    {
        return $this->items->all();
    }

    protected function serializeData(?callable $transform, ?Request $request): array
    {
        return $this->items->map(fn (Item $item) => $this->serializeItem($item, $transform, $request))->values()->all();
    }

    /**
     * Pagination links point at the current request, not at the upstream service.
     */
    protected function responseLinks(?Request $request): array
    {
        return $request !== null ? LinkRewriter::rewriteAll($this->links, $request) : $this->links;
    }
}
