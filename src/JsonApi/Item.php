<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Represents a single JSON:API resource object as a typed value object.
 *
 * Subclass this to add typed accessors for specific resource types.
 */
class Item
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

    /** @return int[] */
    public function relationIds(string $name): array
    {
        $items = data_get($this->relationships, "{$name}.data", []);

        return array_map('intval', array_column((array) $items, 'id'));
    }

    public function relationId(string $name): ?int
    {
        $id = data_get($this->relationships, "{$name}.data.id");

        return $id !== null ? (int) $id : null;
    }

    public function relationshipData(string $name): array
    {
        return data_get($this->relationships, "{$name}.data", []);
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

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }
}
