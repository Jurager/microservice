<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi\Concerns;

use Closure;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\JsonApi\JsonApiRequest;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Http\Resources\JsonApi\RelationResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use JsonSerializable;
use Jurager\Microservice\Exceptions\UnknownIncludeException;
use Jurager\Microservice\JsonApi\Contracts\ProvidesEagerLoads;
use Jurager\Microservice\JsonApi\IncludePlan;
use Jurager\Microservice\JsonApi\ResourceCollection;

/** Batch-load Eloquent relations before JSON:API serialization. */
trait WithEagerIncludes
{
    protected static function newCollection($resource): AnonymousResourceCollection
    {
        return new ResourceCollection($resource, static::class);
    }

    /** Create a new anonymous resource collection. */
    public static function collection($resource): AnonymousResourceCollection
    {
        $request = JsonApiRequest::createFrom(request());
        $plan = new IncludePlan($request);
        $models = $resource instanceof Paginator ? $resource->getCollection() : $resource;

        if ($models instanceof EloquentCollection && $models->isNotEmpty()) {
            // A relation the resource reads lazily is loaded for the whole collection at once, not per model.
            $models->withRelationshipAutoloading();

            $fields = static::sparseFieldsForOwnType($models->first(), $request);
            static::loadEagerIncludes($models, static::getSparseIncludes($request), request()->input('filter', []), $fields, $plan);
        }

        $collection = parent::collection($resource);

        if ($plan->paths() !== null) {
            foreach ($collection->collection as $item) {
                if ($item->usesRequestQueryString) {
                    $item->requestedRelationships = $plan->paths();
                }
            }
        }

        return $collection instanceof ResourceCollection ? $collection->withIncludePlan($plan) : $collection;
    }

    /** Create an HTTP response that represents the object. */
    public function toResponse($request): JsonResponse
    {
        $jsonApiRequest = JsonApiRequest::createFrom($request);

        if ($this->resource instanceof Model) {
            $fields = static::sparseFieldsForOwnType($this->resource, $jsonApiRequest);
            static::loadEagerIncludes(EloquentCollection::make([$this->resource]), static::getSparseIncludes($jsonApiRequest), $request->input('filter', []), $fields);
        }

        return parent::toResponse($request);
    }

    /** Keep internal eager-loads out of included resources; only explicit include expansions are public. */
    protected function requestedResourceRelationships(JsonApiRequest $request, ?string $relationName = null): array
    {
        if (! $this->usesRequestQueryString) {
            return parent::requestedResourceRelationships($request, $relationName);
        }

        // Laravel automatically enables includePreviouslyLoadedRelationships() on every included resource.
        // Those models also carry relations loaded for accessors, inheritance and filtering, not for output.
        if ($this->requestedRelationships !== null) {
            $paths = $this->requestedRelationships;

            if ($relationName === null) {
                return array_values(array_unique(array_map(static fn (string $path) => explode('.', $path, 2)[0], $paths)));
            }

            $prefix = $relationName.'.';
            $paths = array_map(
                static fn (string $path) => substr($path, strlen($prefix)),
                array_filter($paths, static fn (string $path) => str_starts_with($path, $prefix)),
            );
        } else {
            $paths = parent::requestedResourceRelationships($request, $relationName);
        }

        if ($relationName !== null && $this->requestedRelationships === null && $this->resource instanceof Model) {
            $tree = static::buildRelationTree([$relationName => $paths], $this->resource);
            $paths = array_keys(Arr::dot($tree[$relationName]));
        }

        return array_values(array_unique($paths));
    }

    /**
     * Resource attributes, minus the fields that address a relation's rows instead
     * (an EAV code is served through `attribute_values`, not repeated as an attribute).
     */
    protected function resolveResourceAttributes(JsonApiRequest $request, string $resourceType): array
    {
        $usesFieldset = $this->usesRequestQueryString && $request->hasSparseFieldset($resourceType);

        if (! $usesFieldset) {
            return parent::resolveResourceAttributes($request, $resourceType);
        }

        $fields = $request->sparseFields($resourceType);
        $requested = $this->requestedResourceRelationships($request);
        $addressed = [];

        foreach (static::fieldRelationsOf($this->resource, $fields) as $relation => $names) {
            if (in_array($relation, $requested, true)) {
                array_push($addressed, ...$names);
            }
        }

        $attributes = $this->toAttributes($request);

        if ($attributes instanceof Arrayable) {
            $attributes = $attributes->toArray();
        } elseif ($attributes instanceof JsonSerializable) {
            $attributes = $attributes->jsonSerialize();
        }

        $attributes = (new Collection($attributes))
            ->mapWithKeys(fn ($value, $key) => is_int($key) ? [$value => fn () => $this->resource->{$value}] : [$key => $value])
            ->only(array_diff($fields, $addressed))
            ->map(fn ($value) => value($value, $request))
            ->all();

        return $this->filter($attributes);
    }

    /**
     * Ask the model which relations the fieldset's names address, through its optional
     * `public static fieldRelations(array $fields): array` — duck-typed, like loadIncludedRelations(),
     * so neither side depends on the package that supplies the names (e.g. EAV attribute codes
     * selecting rows of `attribute_values`).
     *
     * @param  list<string>  $fields
     * @return array<string, list<string>> Relation => the names among $fields that address its rows.
     */
    protected static function fieldRelationsOf(mixed $model, array $fields): array
    {
        return is_object($model) && method_exists($model, 'fieldRelations') ? $model::fieldRelations($fields) : [];
    }

    /** Get the sparse fields requested for this resource's own JSON:API type, or null when none were requested. */
    protected static function sparseFieldsForOwnType(Model $sample, JsonApiRequest $request): ?array
    {
        $type = (new static($sample))->resolveResourceType($request);

        return $request->hasSparseFieldset($type) ? $request->sparseFields($type) : null;
    }

    /** Get the sparse include map from the JSON:API request. */
    protected static function getSparseIncludes(JsonApiRequest $request): array
    {
        $relations = $request->sparseIncluded() ?? [];

        if (empty($relations)) {
            return [];
        }

        return array_combine(
            $relations,
            array_map(fn (string $relation) => $request->sparseIncluded($relation), $relations)
        );
    }

    /**
     * Load the requested includes into the given model collection.
     *
     * The model's own eagerLoads() runs even when nothing was included: it decides, from the
     * includes and the fieldset it is handed, what its resource reads regardless of the request.
     */
    protected static function loadEagerIncludes(EloquentCollection $models, array $includes, array $filter = [], ?array $fields = null, ?IncludePlan $plan = null): void
    {
        $template = $models->first();

        if ($includes !== [] && ! empty($filter)) {
            static::loadIncludedRelations($models, $template, $filter);
        }

        $tree = static::buildRelationTree($includes, $template);

        static::validateRelationTree($template, $tree);
        static::loadProvidedEagerLoads($models, $template, array_keys($includes), $fields);
        $resource = new static($template);
        $plan?->root($resource, $tree, $models, $resource->usesRequestQueryString);
        static::loadRelationLevel($models, $template, $tree, $resource, $plan);
    }

    /** Apply the included filter scope to a batch of models. */
    protected static function loadIncludedRelations(EloquentCollection $models, Model $template, array $filter): void
    {
        if (method_exists($template, 'loadIncludedRelationsForMany')) {
            $template::loadIncludedRelationsForMany($models, $filter);

            return;
        }

        if (method_exists($template, 'loadIncludedRelations')) {
            foreach ($models as $model) {
                $model->loadIncludedRelations($filter);
            }
        }
    }

    /** Load a model's own declared eager-loads for a batch of its instances. */
    protected static function loadProvidedEagerLoads(EloquentCollection $models, Model $template, array $included, ?array $fields = null): void
    {
        if ($models->isEmpty() || ! $template instanceof ProvidesEagerLoads) {
            return;
        }

        $relations = $template->eagerLoads($included, $fields);

        if ($relations === []) {
            return;
        }

        // A closure constrains its relation (e.g. attribute_values narrowed to the fieldset's codes), so it has
        // to win over a full copy something loaded earlier; loadMissing would keep that copy and skip the constraint.
        $constrained = array_filter($relations, static fn ($relation) => $relation instanceof Closure);

        if ($constrained !== []) {
            $models->load($constrained);
        }

        $models->loadMissing(array_diff_key($relations, $constrained));
    }

    /** Build a nested relation tree, applying eager overrides. */
    protected static function buildRelationTree(array $includes, Model $owner): array
    {
        $tree = [];

        foreach ($includes as $relation => $nested) {
            $tree[$relation] = static::buildTreeFromPaths(array_values(array_filter((array) $nested)));
        }

        static::validateRelationTree($owner, $tree);

        return static::expandRelationTree($owner, $tree);
    }

    /** Apply declarations to requested nodes before merging their defaults, keeping recursive defaults finite. */
    protected static function expandRelationTree(Model $owner, array $tree): array
    {
        $overrides = method_exists($owner, 'eagerRelations') ? $owner::eagerRelations() : [];

        foreach ($tree as $relation => $children) {
            if ($children !== []) {
                $children = static::expandRelationTree($owner->{$relation}()->getRelated(), $children);
            }

            $prefix = $relation.'.';
            $paths = array_map(
                static fn (string $path) => str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path,
                $overrides[$relation] ?? [],
            );

            $tree[$relation] = array_replace_recursive(static::buildTreeFromPaths($paths), $children);
        }

        return $tree;
    }

    /** Recursively validate every path in the tree before any query runs. */
    protected static function validateRelationTree(Model $template, array $tree, string $prefix = ''): void
    {
        foreach ($tree as $relation => $children) {
            $path = $prefix === '' ? $relation : "$prefix.$relation";

            if (! $template->isRelation($relation)) {
                throw new UnknownIncludeException($path);
            }

            if (! empty($children)) {
                static::validateRelationTree($template->{$relation}()->getRelated(), $children, $path);
            }
        }
    }

    /** Recursively load a single level of the (already validated) relation tree onto the models. */
    protected static function loadRelationLevel(EloquentCollection $models, Model $template, array $tree, ?JsonApiResource $resource = null, ?IncludePlan $plan = null): void
    {
        if ($models->isEmpty() || empty($tree)) {
            return;
        }

        $request = JsonApiRequest::createFrom(request());
        $resolvers = [];

        $definitions = $resource?->toRelationships($request) ?? [];

        foreach ($definitions as $name => $resolver) {
            $resolver = is_int($name) ? new RelationResolver($resolver) : new RelationResolver($name, $resolver);
            $resolvers[$resolver->relationName] = $resolver;
        }

        $relatedResources = [];

        // Prepare siblings before traversing their children. A model's constrained loads can
        // replace relations also used by a sibling resolver (for example, inherited values).
        foreach ($tree as $relation => $children) {
            $models->loadMissing($relation);
            $relatedTemplate = $template->{$relation}()->getRelated();
            $relatedModels = static::relatedModels($models, $relation)->withRelationshipAutoloading();

            $relatedResource = rescue(
                fn () => $relatedTemplate->toResource(($resolvers[$relation] ?? null)?->resourceClass()),
                report: false,
            );
            $relatedResources[$relation] = $relatedResource instanceof JsonApiResource ? $relatedResource : null;

            if ($children === [] && ! $relatedTemplate instanceof ProvidesEagerLoads) {
                if (($definitions[$relation] ?? null) instanceof Closure) {
                    $plan?->requireSeparateTraversal();
                }

                $plan?->add($relatedResources[$relation], $children, $relatedModels, $relatedResources[$relation]?->usesRequestQueryString ?? false);

                continue;
            }

            $fields = $relatedResource instanceof JsonApiResource
                ? static::fieldsForResource($relatedResource, $request)
                : null;

            static::loadProvidedEagerLoads($relatedModels, $relatedTemplate, array_keys($children), $fields);
        }

        foreach ($tree as $relation => $children) {
            $relatedTemplate = $template->{$relation}()->getRelated();

            if ($children === [] && ! $relatedTemplate instanceof ProvidesEagerLoads) {
                continue;
            }

            $relatedResource = $relatedResources[$relation];
            $relatedModels = static::relatedModels($models, $relation);

            // Follow the resource's resolved models, which may differ from the raw relation.
            // Use Laravel's resolver so resource collections and single resources behave alike.
            $definition = $definitions[$relation] ?? null;

            if ($definition instanceof Closure) {
                $relatedModels = new EloquentCollection;
                $resolvedClass = null;

                foreach ($models as $model) {
                    $definition = $resource::make($model)->toRelationships($request)[$relation];
                    $resolver = new RelationResolver($relation, $definition);
                    $resolved = $resolver->handle($model);

                    if ($resolved instanceof EloquentCollection) {
                        $relatedModels->push(...$resolved);
                    } elseif ($resolved instanceof Model) {
                        $relatedModels->push($resolved);
                    }

                    if ($resourceClass = $resolver->resourceClass()) {
                        if ($resolvedClass !== null && $resolvedClass !== $resourceClass) {
                            $plan?->requireSeparateTraversal();
                        }

                        $resolvedClass = $resourceClass;
                        $relatedResource = new $resourceClass($relatedTemplate);
                    }
                }

                $relatedModels = $relatedModels->uniqueStrict(static fn (Model $model) => $model)->values();
                static::loadProvidedEagerLoads(
                    $relatedModels, $relatedTemplate, array_keys($children),
                    $relatedResource ? static::fieldsForResource($relatedResource, $request) : null,
                );
            }

            $relatedModels->withRelationshipAutoloading();
            $plan?->add($relatedResource, $children, $relatedModels, $relatedResource?->usesRequestQueryString ?? false);

            if ($children !== []) {
                static::loadRelationLevel($relatedModels, $relatedTemplate, $children, $relatedResource, $plan);
            }
        }
    }

    /** Prepare shared instances once while retaining distinct models with different pivot data. */
    protected static function relatedModels(EloquentCollection $models, string $relation): EloquentCollection
    {
        return EloquentCollection::make($models->pluck($relation)->flatten(1)->filter())
            ->uniqueStrict(static fn (Model $model) => $model)
            ->values();
    }

    /** The fieldset belongs to the related resource's type, not its owner's type. */
    protected static function fieldsForResource(JsonApiResource $resource, JsonApiRequest $request): ?array
    {
        $type = $resource->resolveResourceType($request);

        return $request->hasSparseFieldset($type) ? $request->sparseFields($type) : null;
    }

    /** Group an array of dotted paths into a multi-dimensional tree array. */
    protected static function buildTreeFromPaths(array $paths): array
    {
        $tree = [];

        foreach ($paths as $path) {
            $node = &$tree;

            foreach (explode('.', $path) as $segment) {
                $node[$segment] ??= [];
                $node = &$node[$segment];
            }

            unset($node);
        }

        return $tree;
    }
}
