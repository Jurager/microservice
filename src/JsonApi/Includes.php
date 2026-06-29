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
        $this->raw = array_map(fn (array $r) => array_diff_key($r, ['links' => true]), $included);

        foreach ($included as $resource) {
            $type = $resource['type'] ?? null;
            $id = (string) ($resource['id'] ?? '');

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
        return $this->index[$type][$id] ?? null;
    }

    public function has(string $type, string $id): bool
    {
        return isset($this->index[$type][$id]);
    }

    /** Raw included array for forwarding to the frontend as-is. */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * Filter included resources in-place without orphan/reachability pruning.
     * Use this when you simply want to reduce the included set by a predicate.
     */
    public function filter(callable $predicate): void
    {
        $this->raw = array_values(array_filter($this->raw, $predicate));
        $this->rebuildIndex();
    }

    /**
     * Apply a filter predicate, clean up orphan relationship links in root items,
     * then cascade-prune any included resources no longer reachable from those roots.
     *
     * When roots is empty the BFS step is skipped and only the filter is applied.
     *
     * @param  Item[]  $roots  primary data items (modified in place via withoutOrphanLinks)
     */
    public function rebuild(callable $filter, array $roots): void
    {
        $this->filter($filter);

        if (empty($roots)) {
            return;
        }

        foreach ($roots as $root) {
            $root->withoutOrphanLinks($this);
        }

        $reachable = [];
        $queue = array_map(fn (Item $item) => $item->toArray(), $roots);

        while ($queue) {
            $resource = array_shift($queue);

            foreach ($resource['relationships'] ?? [] as $rel) {
                $data = $rel['data'] ?? null;

                if ($data === null) {
                    continue;
                }

                foreach (array_is_list($data) ? $data : [$data] as $ref) {
                    $type = $ref['type'] ?? '';
                    $id = (string) ($ref['id'] ?? '');
                    $key = "$type:$id";

                    if ($type && $id && ! isset($reachable[$key])) {
                        $reachable[$key] = true;
                        $found = $this->index[$type][$id] ?? null;

                        if ($found) {
                            $queue[] = $found;
                        }
                    }
                }
            }
        }

        $this->raw = array_values(array_filter(
            $this->raw,
            fn (array $r) => isset($reachable[($r['type'] ?? '').':'.($r['id'] ?? '')]),
        ));

        $this->rebuildIndex();
    }

    /**
     * Auto-resolve all relationships present in this included pool using Item::class.
     * Skips relationships already resolved via withRelations().
     */
    public function autoAttach(Item $item): void
    {
        if ($this->isEmpty()) {
            return;
        }

        $map = array_fill_keys(
            array_keys($item->relationships()),
            Item::class,
        );

        foreach ($map as $name => $class) {
            if (! $item->hasResolved($name)) {
                $this->attachRelations($item, [$name => $class]);
            }
        }
    }

    /**
     * Resolve all relationships of an Item and attach them.
     *
     * JSON:API spec:
     *   to-many → data is a sequential array (possibly empty)
     *   to-one  → data is an associative object (possibly null)
     *
     * Map values can be:
     *   - a class-string<Item>: resolve with that class, no sub-relations
     *   - [class-string<Item>, array $subMap]: resolve with that class, then recurse into subMap
     *
     * @param  array<string, class-string<Item>|array{0: class-string<Item>, 1: array}>  $map
     */
    public function attachRelations(Item $item, array $map): void
    {
        foreach ($map as $name => $spec) {
            [$itemClass, $subMap] = is_array($spec) ? $spec : [$spec, []];

            $data = $item->relationshipData($name);

            if (array_is_list($data)) {
                $resolved = array_values(array_filter(
                    array_map(fn (array $ref) => $this->resolveRef($ref, $itemClass, $subMap), $data)
                ));
                $item->setResolved($name, $resolved);
            } else {
                $resolved = $data ? $this->resolveRef($data, $itemClass, $subMap) : null;
                $item->setResolved($name, $resolved ? [$resolved] : []);
            }
        }
    }

    private function resolveRef(array $ref, ?string $itemClass, array $subMap = []): ?Item
    {
        $type = $ref['type'] ?? null;
        $id = (string) ($ref['id'] ?? '');
        $resource = $type ? ($this->index[$type][$id] ?? null) : null;

        if (! $resource) {
            return null;
        }

        $resolved = Item::from($resource, $itemClass);

        if ($subMap) {
            $this->attachRelations($resolved, $subMap);
        }

        return $resolved;
    }

    private function rebuildIndex(): void
    {
        $this->index = [];

        foreach ($this->raw as $resource) {
            $type = $resource['type'] ?? null;
            $id = (string) ($resource['id'] ?? '');

            if ($type && $id) {
                $this->index[$type][$id] = $resource;
            }
        }
    }
}
