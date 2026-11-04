<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use ArrayAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * A JSON:API resource with attribute and relationship accessors.
 *
 * @implements ArrayAccess<string, mixed>
 */
class Item implements ArrayAccess
{
    public readonly string $id;

    public readonly string $type;

    private array $attributes;

    private array $relationships;

    private array $links;

    private array $meta;

    private array $resolved = [];

    final public function __construct(array $resource)
    {
        $this->id = (string) ($resource['id'] ?? '');
        $this->type = (string) ($resource['type'] ?? '');
        $this->attributes = $resource['attributes'] ?? [];
        $this->relationships = $resource['relationships'] ?? [];
        $this->links = $resource['links'] ?? [];
        $this->meta = $resource['meta'] ?? [];
    }

    /**
     * @template T of Item
     *
     * @param  class-string<T>|null  $class
     */
    public static function from(array $resource, ?string $class = null): static
    {
        $target = $class ?? static::class;

        return new $target($resource);
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return data_get($this->attributes, $key, $default);
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * Ids of a to-many relationship: numeric ids as integers, any other — a UUID, a code — as given.
     *
     * @return list<int|string>
     */
    public function relationIds(string $name): array
    {
        $items = data_get($this->relationships, "$name.data", []);

        return array_values(array_filter(
            array_map(self::identifier(...), array_column((array) $items, 'id')),
            static fn (int|string|null $id): bool => $id !== null,
        ));
    }

    /**
     * Id of a to-one relationship: a numeric id as an integer, any other as given.
     */
    public function relationId(string $name): int|string|null
    {
        return self::identifier(data_get($this->relationships, "$name.data.id"));
    }

    private static function identifier(mixed $id): int|string|null
    {
        return match (true) {
            is_int($id) => $id,
            is_string($id) && ctype_digit($id) => (int) $id,
            is_string($id) && $id !== '' => $id,
            default => null,
        };
    }

    public function relationshipData(string $name): array
    {
        return data_get($this->relationships, "$name.data") ?? [];
    }

    /**
     * Remove relationship links that reference resources no longer in `included`.
     */
    public function withoutOrphanLinks(Includes $included): void
    {
        $this->relationships = self::pruneRelationships($this->relationships, $included);
    }

    /**
     * Strip refs from a `relationships` array that point to a resource no longer in `included`.
     *
     * @param  array<string, array{data: array|list<array>|null}>  $relationships
     * @return array<string, array{data: array|list<array>}>
     */
    public static function pruneRelationships(array $relationships, Includes $included): array
    {
        foreach ($relationships as $name => &$rel) {
            $data = $rel['data'] ?? null;

            if ($data === null) {
                continue;
            }

            $items = array_is_list($data) ? $data : [$data];

            $kept = array_values(array_filter($items, fn (array $ref): bool => $included->has(
                (string) ($ref['type'] ?? ''),
                (string) ($ref['id'] ?? ''),
            )));

            if ($kept === []) {
                unset($relationships[$name]);
            } else {
                $rel['data'] = array_is_list($data) ? $kept : $kept[0];
            }
        }

        unset($rel);

        return $relationships;
    }

    public function relationships(): array
    {
        return $this->relationships;
    }

    public function links(): array
    {
        return $this->links;
    }

    public function meta(): array
    {
        return $this->meta;
    }

    /** @param  Item[]  $items */
    public function setResolved(string $name, array $items): void
    {
        $this->resolved[$name] = $items;
    }

    /**
     * @template T of Item
     *
     * @return Collection<int, T>
     */
    public function getRelation(string $name): Collection
    {
        return Collection::make($this->resolved[$name] ?? []);
    }

    /** @return T|null */
    public function getRelationOne(string $name): ?Item
    {
        return $this->resolved[$name][0] ?? null;
    }

    public function hasResolved(string $name): bool
    {
        return isset($this->resolved[$name]);
    }

    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'type' => $this->type,
            'attributes' => $this->attributes,
        ];

        if ($this->relationships) {
            $data['relationships'] = $this->relationships;
        }
        if ($this->links) {
            $data['links'] = $this->links;
        }
        if ($this->meta) {
            $data['meta'] = $this->meta;
        }

        return $data;
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse(['data' => $this->toArray()], 200, ['Content-Type' => 'application/vnd.api+json']);
    }

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }
}
