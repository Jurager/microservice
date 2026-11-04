<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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

    /**
     * Create a collection from JSON:API documents.
     *
     * @template TItem of Item
     *
     * @param  iterable<array<string, mixed>>  $documents
     * @param  class-string<TItem>  $itemClass
     * @param  bool  $scoped  Use document keys to scope resource IDs.
     * @return static<TItem>
     */
    public static function collect(iterable $documents, string $itemClass = Item::class, bool $scoped = false): static
    {
        return new static(static::merge($documents, $scoped), $itemClass);
    }

    /**
     * Merge data and includes without pagination.
     *
     * @param  iterable<array<string, mixed>>  $documents
     * @param  bool  $scoped  Prefix IDs with source keys and keep originals in meta.origin.
     * @return array{data: list<array>, included: list<array>}
     *
     * @throws InvalidArgumentException
     */
    public static function merge(iterable $documents, bool $scoped = false): array
    {
        $result = ['data' => [], 'included' => []];

        foreach ($documents as $source => $document) {
            if ($scoped && (! is_string($source) || $source === '')) {
                throw new InvalidArgumentException('Scoped documents must have non-empty string source keys.');
            }

            foreach (['data', 'included'] as $member) {
                $resources = $document[$member] ?? [];

                if ($scoped) {
                    $resources = array_map(static fn (array $resource): array => static::scope($resource, $source), $resources);
                }

                array_push($result[$member], ...$resources);
            }
        }

        return $result;
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

    private static function scope(array $resource, string $source): array
    {
        $prefix = rawurlencode($source).':';
        $id = (string) $resource['id'];
        $resource['id'] = $prefix.rawurlencode($id);
        $resource['meta']['origin'] = ['source' => $source, 'id' => $id];

        foreach ($resource['relationships'] ?? [] as $name => $relationship) {
            $data = $relationship['data'] ?? null;

            if ($data === null) {
                continue;
            }

            $many = array_is_list($data);
            $identifiers = $many ? $data : [$data];

            foreach ($identifiers as $index => $identifier) {
                $identifiers[$index]['id'] = $prefix.rawurlencode((string) $identifier['id']);
            }

            $resource['relationships'][$name]['data'] = $many ? $identifiers : $identifiers[0];
        }

        return $resource;
    }
}
