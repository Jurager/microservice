<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Represents a JSON:API collection response.
 *
 * @template T of Item
 */
class CollectionDocument implements Responsable
{
    /** @var Collection<int, T> */
    private Collection $items;

    private array $meta;

    private array $links;

    private Includes $included;

    /** @param  class-string<T>  $itemClass */
    public function __construct(array $body, private readonly string $itemClass = Item::class)
    {
        $this->meta = $body['meta'] ?? [];
        $this->links = $body['links'] ?? [];
        $this->included = new Includes($body['included'] ?? []);

        $this->items = Collection::make($body['data'] ?? [])
            ->map(fn (array $resource) => Item::from($resource, $this->itemClass));

        $this->items->each(fn (Item $item) => $this->included->autoAttach($item));
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

    public function filterIncluded(callable $filter): static
    {
        $this->included->filter($filter);
        $this->items->each(fn (Item $item) => $item->withoutOrphanLinks($this->included));

        return $this;
    }

    public function transformIncluded(callable $transformer): static
    {
        $this->included->transform($transformer);

        return $this;
    }

    public function applyPolicy(callable $policy): static
    {
        $this->included->applyPolicy($policy);
        $this->included->pruneUnreachable($this->items->all());

        return $this;
    }

    /**
     * Remove relationship links that reference resources no longer in `included`.
     */
    public function withoutOrphans(): static
    {
        $this->items->each(fn (Item $item) => $item->withoutOrphanLinks($this->included));

        return $this;
    }

    public function rawIncluded(): array
    {
        return $this->included->raw();
    }

    /**
     * Build a JSON:API response.
     *
     * Accepts three call forms:
     *   toResponse()              — serialize items as-is
     *   toResponse($request)      — apply included policy registered on the request, then serialize
     *   toResponse($transform)    — serialize each item through a callable transform
     *
     * @param  Request|callable(T): array|null  $requestOrTransform
     */
    public function toResponse(mixed $requestOrTransform = null): JsonResponse
    {
        $transform = null;

        if ($requestOrTransform instanceof Request) {
            if (! $this->included->isEmpty()) {
                $policy = $requestOrTransform->attributes->get(Includes::class);
                if ($policy) {
                    $this->applyPolicy($policy);
                }
            }
        } elseif (is_callable($requestOrTransform)) {
            $transform = $requestOrTransform;
        }

        return $this->buildResponse($transform);
    }

    /** @param  callable(T): array|null  $transform */
    public function toJsonResponse(?callable $transform = null): JsonResponse
    {
        return $this->buildResponse($transform);
    }

    private function buildResponse(?callable $transform): JsonResponse
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
        return LinkRewriter::rewriteAll($this->links);
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
