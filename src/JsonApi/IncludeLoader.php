<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Http\Resources\JsonApi\JsonApiRequest;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Http\Resources\PotentiallyMissing;
use Illuminate\Support\Collection as BaseCollection;
use Jurager\Microservice\Exceptions\UnknownIncludeException;
use Jurager\Microservice\JsonApi\Contracts\DefinesEagerRelations;
use Jurager\Microservice\JsonApi\Contracts\FiltersIncludedRelations;
use Jurager\Microservice\JsonApi\Contracts\ProvidesEagerLoads;
use WeakMap;

/**
 * Loads the requested includes of resources level by level, in one batch per resource class and path.
 */
class IncludeLoader
{
    public readonly JsonApiRequest $request;

    private array $includes;

    /** @var array<string, true> */
    private array $requested = [];

    private array $filter;

    /** @var array<string, ResourceNode> */
    private array $nodes = [];

    /** @var array<class-string, array<string, mixed>|null> */
    private array $relationships = [];

    /** @var array<string, array<string, mixed>> */
    private array $callbacks = [];

    /** @var array<class-string, class-string<JsonApiResource>> */
    private array $resourceClasses = [];

    /** @var WeakMap<Model, array<string, true>> */
    private WeakMap $prepared;

    public function __construct(Request $request)
    {
        $this->request = $request instanceof JsonApiRequest ? $request : JsonApiRequest::createFrom($request);
        $this->prepared = new WeakMap;

        $filter = $request->input('filter');
        $this->filter = is_array($filter) ? $filter : [];

        $depth = max(1, JsonApiResource::$maxRelationshipDepth);
        $paths = [];

        foreach (explode(',', (string) $request->input('include', '')) as $path) {
            $segments = array_slice(explode('.', trim($path)), 0, $depth);
            $paths[] = implode('.', $segments);

            for ($length = 1; $length <= count($segments); $length++) {
                $this->requested[implode('.', array_slice($segments, 0, $length))] = true;
            }
        }

        $this->includes = self::tree($paths);
    }

    /**
     * @param  iterable<JsonApiResource>  $resources
     * @return list<ResourceNode>
     */
    public function load(iterable $resources): array
    {
        $roots = [];

        foreach ($resources as $resource) {
            $roots[] = $this->node($resource, $this->includes, '');
        }

        for ($level = $roots; $level !== [];) {
            $groups = [];

            foreach ($level as $node) {
                $groups[$node->resource::class.'@'.get_debug_type($node->resource->resource).'@'.$node->path][spl_object_id($node)] = $node;
            }

            $level = [];

            foreach ($groups as $nodes) {
                array_push($level, ...$this->loadLevel(array_values($nodes)));
            }
        }

        return $roots;
    }

    /** @return list<ResourceNode> */
    public function resolve(ResourceNode $node, string $name, mixed $resolver): array
    {
        $model = $node->resource->resource;
        $resolved = $resolver instanceof Closure ? $resolver($model) : $model->getRelation($name);

        if ($resolved instanceof PotentiallyMissing && $resolved->isMissing()) {
            return [];
        }

        $class = is_string($resolver) ? $resolver : null;

        if ($resolved instanceof AnonymousResourceCollection) {
            $class = $resolved->collects;
            $resolved = new Collection($resolved->collection->map->resource->all());
        } elseif ($resolved instanceof JsonApiResource) {
            $class = $resolved::class;
            $resolved = $resolved->resource;
        }

        $many = $resolved instanceof BaseCollection;

        if (! $many && $resolved instanceof Pivot) {
            return [];
        }

        $path = $node->path === '' ? $name : $node->path.'.'.$name;
        $children = [];

        foreach ($many ? $resolved : [$resolved] as $item) {
            if ($item instanceof Model) {
                $children[] = $this->node($this->resource($item, $class), $node->tree[$name], $path);
            }
        }

        $node->relationships[$name] = ['many' => $many, 'resources' => $children];

        return $children;
    }

    public function fields(JsonApiResource $resource): ?array
    {
        // Laravel's own switch, set by ignoreFieldsAndIncludesInQueryString() — on every JSON:API resource.
        if (! (fn (): bool => $this->usesRequestQueryString)->call($resource)) {
            return null;
        }

        $type = $resource->resolveResourceType($this->request);

        return $this->request->hasSparseFieldset($type) ? $this->request->sparseFields($type) : null;
    }

    /** @param  list<string>  $paths */
    public static function tree(array $paths): array
    {
        $tree = [];

        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }

            $node = &$tree;

            foreach (explode('.', $path) as $segment) {
                $node = &$node[$segment];
                $node ??= [];
            }

            unset($node);
        }

        return $tree;
    }

    /**
     * @param  list<ResourceNode>  $nodes
     * @return list<ResourceNode>
     */
    private function loadLevel(array $nodes): array
    {
        $sample = $nodes[0];
        $models = $this->models($nodes);

        if ($models->isEmpty()) {
            return [];
        }

        $this->prepare($models, $sample->resource, array_keys($sample->tree), $sample->path === '');
        $models->withRelationshipAutoloading();
        $models->loadMissing(array_keys($sample->tree));

        // Prepare related models before callbacks read the values their eager loads replace.
        foreach ($sample->tree as $name => $children) {
            $resolver = $this->resolver($sample->resource, $name);

            foreach ($this->related($models, $name)->groupBy(static fn (Model $model): string => $model::class) as $group) {
                $this->prepare($group, $this->resource($group->first(), is_string($resolver) ? $resolver : null), array_keys($children));
            }
        }

        $next = [];

        foreach ($nodes as $node) {
            foreach ($node->tree as $name => $children) {
                $resolver = $this->resolver($node->resource, $name);

                if ($resolver instanceof Closure && $children === [] && ! $this->providesEagerLoads($node->resource->resource->getRelation($name))) {
                    $node->deferred[$name] = $resolver;

                    continue;
                }

                array_push($next, ...$this->resolve($node, $name, $resolver));
            }
        }

        return $next;
    }

    private function node(JsonApiResource $resource, array $tree, string $path): ResourceNode
    {
        $type = $resource->resolveResourceType($this->request);
        $id = $resource->resolveResourceIdentifier($this->request);
        $key = $resource::class.'@'.$type.':'.$id.'@'.$path;

        if (isset($this->nodes[$key])) {
            return $this->nodes[$key];
        }

        if ($resource->resource instanceof Model) {
            $tree = $this->expand($resource->resource, $tree, $path);
        }

        if ($path !== '') {
            $resource->includePreviouslyLoadedRelationships();
        }

        return $this->nodes[$key] = new ResourceNode($resource, $type, $id, $path, $tree);
    }

    private function expand(Model $model, array $tree, string $path): array
    {
        $declared = $model instanceof DefinesEagerRelations ? $model::eagerRelations() : [];

        foreach ($tree as $name => $children) {
            $fullPath = $path === '' ? $name : $path.'.'.$name;

            if (! $model->isRelation($name)) {
                throw new UnknownIncludeException($fullPath);
            }

            if (isset($this->requested[$fullPath])) {
                $prefix = $name.'.';
                $defaults = array_map(
                    static fn (string $default): string => str_starts_with($default, $prefix) ? substr($default, strlen($prefix)) : $default,
                    $declared[$name] ?? [],
                );
                $tree[$name] = array_replace_recursive(self::tree($defaults), $children);
            }
        }

        return $tree;
    }

    private function prepare(Collection $models, JsonApiResource $resource, array $included, bool $filtered = false): void
    {
        $template = $models->first();
        $fields = $this->fields($resource);
        $signature = json_encode([$included, $fields], JSON_THROW_ON_ERROR);
        $models = $models->filter(fn (Model $model): bool => ! isset($this->prepared[$model][$signature]));

        if ($models->isEmpty()) {
            return;
        }

        foreach ($models as $model) {
            $this->prepared[$model] = [...$this->prepared[$model] ?? [], $signature => true];
        }

        if ($filtered && $included !== [] && $this->filter !== [] && $template instanceof FiltersIncludedRelations) {
            $template::loadIncludedRelationsForMany($models, $this->filter);
        }

        if (! $template instanceof ProvidesEagerLoads) {
            return;
        }

        $loads = $template->eagerLoads($included, $fields);

        // Constrained loads must replace relations loaded earlier, loadMissing() would keep them.
        $constraints = array_filter($loads, static fn (mixed $load): bool => $load instanceof Closure);

        if ($constraints !== []) {
            $models->load($constraints);
        }

        $models->loadMissing(array_diff_key($loads, $constraints));
    }

    private function resolver(JsonApiResource $resource, string $name): mixed
    {
        $class = $resource::class;

        if (! array_key_exists($class, $this->relationships)) {
            $declared = $this->declaredRelationships($resource);
            $this->relationships[$class] = array_any($declared, static fn (mixed $resolver): bool => $resolver instanceof Closure) ? null : $declared;
        }

        $relationships = $this->relationships[$class]
            ?? ($this->callbacks[$class.':'.spl_object_id($resource->resource)] ??= $this->declaredRelationships($resource));

        return $relationships[$name] ?? null;
    }

    /** @return array<string, mixed> */
    private function declaredRelationships(JsonApiResource $resource): array
    {
        $declared = $resource->toRelationships($this->request);
        $relationships = [];

        foreach ($declared instanceof Arrayable ? $declared->toArray() : $declared as $name => $resolver) {
            $relationships[is_int($name) ? $resolver : $name] = is_int($name) ? null : $resolver;
        }

        return $relationships;
    }

    private function resource(Model $model, ?string $class): JsonApiResource
    {
        if ($class === null) {
            if (! array_key_exists($model::class, $this->resourceClasses)) {
                $inferred = rescue(fn () => $model->toResource(), report: false);
                $this->resourceClasses[$model::class] = $inferred instanceof JsonApiResource ? $inferred::class : JsonApiResource::class;
            }

            $class = $this->resourceClasses[$model::class];
        }

        return new $class($model);
    }

    private function providesEagerLoads(mixed $related): bool
    {
        return $related instanceof ProvidesEagerLoads
            || ($related instanceof BaseCollection && $related->first() instanceof ProvidesEagerLoads);
    }

    /** @param  list<ResourceNode>  $nodes */
    private function models(array $nodes): Collection
    {
        $models = [];

        foreach ($nodes as $node) {
            if ($node->resource->resource instanceof Model) {
                $models[spl_object_id($node->resource->resource)] = $node->resource->resource;
            }
        }

        return new Collection(array_values($models));
    }

    private function related(Collection $models, string $name): Collection
    {
        $related = [];

        foreach ($models as $model) {
            $value = $model->getRelation($name);

            foreach ($value instanceof BaseCollection ? $value : [$value] as $item) {
                if ($item instanceof Model) {
                    $related[spl_object_id($item)] = $item;
                }
            }
        }

        return new Collection(array_values($related));
    }
}
