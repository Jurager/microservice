<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

class Includes
{
    /** @var array<string, array<string, array>> */
    private array $index = [];

    private array $raw;

    /** @var array<string, array<string, Item>> */
    private array $primary = [];

    public function __construct(array $included = [])
    {
        $resources = [];

        foreach ($included as $resource) {
            $type = $resource['type'] ?? null;
            $id = (string) ($resource['id'] ?? '');

            if (is_string($type) && $type !== '' && $id !== '') {
                $key = $type.':'.$id;
                $resources[$key] = isset($resources[$key]) ? self::merge($resources[$key], $resource) : $resource;
            }
        }

        $this->raw = array_values(array_map(static fn (array $resource): array => array_diff_key($resource, ['links' => true]), $resources));

        foreach ($resources as $resource) {
            $this->index[$resource['type']][(string) $resource['id']] = $resource;
        }
    }

    /**
     * Merge two objects of the same resource, combining their members and relationship linkage.
     */
    public static function merge(array $resource, array $duplicate): array
    {
        foreach (['attributes', 'links', 'meta'] as $member) {
            if (isset($duplicate[$member])) {
                $resource[$member] = ($resource[$member] ?? []) + $duplicate[$member];
            }
        }

        foreach ($duplicate['relationships'] ?? [] as $name => $relationship) {
            $data = $relationship['data'] ?? null;
            $known = $resource['relationships'][$name]['data'] ?? null;

            if (! isset($resource['relationships'][$name])) {
                $resource['relationships'][$name] = $relationship;
            } elseif (is_array($data) && array_is_list($data) && is_array($known) && array_is_list($known)) {
                $identifiers = [];

                foreach ([...$known, ...$data] as $identifier) {
                    $identifiers[json_encode($identifier, JSON_THROW_ON_ERROR)] = $identifier;
                }

                $resource['relationships'][$name]['data'] = array_values($identifiers);
            } elseif ($known === null && $data !== null) {
                $resource['relationships'][$name]['data'] = $data;
            }
        }

        return $resource;
    }

    /**
     * Register primary resources for linkage resolution without adding them to included.
     */
    public function registerPrimary(array $items): void
    {
        foreach ($items as $item) {
            if ($item->type !== '' && $item->id !== '') {
                $this->primary[$item->type][$item->id] = $item;
                unset($this->index[$item->type][$item->id]);

                if (isset($this->index[$item->type]) && $this->index[$item->type] === []) {
                    unset($this->index[$item->type]);
                }
            }
        }

        $this->raw = array_values(array_filter($this->raw, fn (array $resource): bool => ! isset($this->primary[$resource['type'] ?? ''][(string) ($resource['id'] ?? '')])));
    }

    public function isEmpty(): bool
    {
        return empty($this->index);
    }

    public function find(string $type, string $id): ?array
    {
        return isset($this->primary[$type][$id]) ? $this->primary[$type][$id]->toArray() : ($this->index[$type][$id] ?? null);
    }

    public function has(string $type, string $id): bool
    {
        return isset($this->primary[$type][$id]) || isset($this->index[$type][$id]);
    }

    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * Apply a policy to included resources, removing those that return null.
     *
     * @param  callable(array): ?array  $policy
     */
    public function applyPolicy(callable $policy): void
    {
        $this->raw = array_values(array_filter(array_map($policy, $this->raw), static fn (?array $resource): bool => $resource !== null));
        $this->rebuildIndex();
    }

    /**
     * Remove included resources unreachable from the given roots, and strip the roots.
     *
     * @param  array<Item>  $roots
     */
    public function pruneUnreachable(array $roots): void
    {
        if (empty($roots)) {
            return;
        }

        foreach ($roots as $root) {
            $root->withoutOrphanLinks($this);
        }

        $reachable = [];
        $queue = array_map(fn (Item $item) => $item->toArray(), $roots);
        foreach ($roots as $root) {
            $reachable[$root->type.':'.$root->id] = true;
        }

        for ($cursor = 0; $cursor < count($queue); $cursor++) {
            $resource = $queue[$cursor];

            foreach ($resource['relationships'] ?? [] as $rel) {
                $data = $rel['data'] ?? null;

                if ($data === null) {
                    continue;
                }

                foreach (array_is_list($data) ? $data : [$data] as $ref) {
                    $type = $ref['type'] ?? '';
                    $id = (string) ($ref['id'] ?? '');
                    $key = "$type:$id";

                    if ($type !== '' && $id !== '' && ! isset($reachable[$key])) {
                        $reachable[$key] = true;
                        $found = $this->find($type, $id);

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

        $this->raw = array_map(function (array $resource) {
            if (isset($resource['relationships'])) {
                $resource['relationships'] = Item::pruneRelationships($resource['relationships'], $this);
            }

            return $resource;
        }, $this->raw);

        $this->rebuildIndex();
    }

    public function autoAttach(Item $item): void
    {
        if ($this->isEmpty() && $this->primary === []) {
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
     * Resolve and attach item relationships using the given resource map.
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
        $resource = $type !== null ? $this->find($type, $id) : null;

        if (! $resource) {
            return null;
        }

        $class = $itemClass ?? Item::class;
        $primary = $this->primary[$type][$id] ?? null;
        $resolved = $primary instanceof $class ? $primary : Item::from($resource, $class);

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

            if (is_string($type) && $type !== '' && $id !== '') {
                $this->index[$type][$id] = $resource;
            }
        }
    }
}
