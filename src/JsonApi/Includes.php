<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

/**
 * Holds the top-level `included` array from a JSON:API response.
 * Indexes resources by [type][id] for O(1) lookup and resolves
 * relationship linkage into typed Item objects.
 */
class Includes
{
    /** @var array<string, array<string, array>> */
    private array $index = [];

    private array $raw;

    public function __construct(array $included = [])
    {
        $this->raw = $included;

        foreach ($included as $resource) {
            $type = $resource['type'] ?? null;
            $id   = (string) ($resource['id'] ?? '');

            if ($type && $id) {
                $this->index[$type][$id] = $resource;
            }
        }
    }

    public function isEmpty(): bool
    {
        return empty($this->index);
    }

    public function find(string $type, string $id): ?array
    {
        return $this->index[$type][(string) $id] ?? null;
    }

    /** Raw included array for forwarding to the frontend as-is. */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * Resolve all relationships of an Item and attach them.
     *
     * JSON:API spec:
     *   to-many → data is a sequential array (possibly empty)
     *   to-one  → data is an associative object (possibly null)
     *
     * @param  array<string, class-string<Item>>  $map  ['relationName' => ItemClass::class]
     */
    public function attachRelations(Item $item, array $map): void
    {
        foreach ($map as $name => $itemClass) {
            $data = $item->relationshipData($name);

            if (is_array($data) && array_is_list($data)) {
                // to-many: sequential array of resource identifier objects
                $resolved = array_values(array_filter(
                    array_map(fn (array $ref) => $this->resolveRef($ref, $itemClass), $data)
                ));
                $item->setResolved($name, $resolved);
            } else {
                // to-one: associative object or null/empty
                $resolved = $data ? $this->resolveRef($data, $itemClass) : null;
                $item->setResolved($name, $resolved ? [$resolved] : []);
            }
        }
    }

    private function resolveRef(array $ref, ?string $itemClass): ?Item
    {
        $type     = $ref['type'] ?? null;
        $id       = (string) ($ref['id'] ?? '');
        $resource = $type ? ($this->index[$type][$id] ?? null) : null;

        return $resource ? Item::from($resource, $itemClass) : null;
    }
}
