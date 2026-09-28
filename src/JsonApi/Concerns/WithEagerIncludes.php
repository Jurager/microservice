<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi\Concerns;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\JsonApi\JsonApiRequest;
use Jurager\Microservice\Exceptions\UnknownIncludeException;
use Jurager\Microservice\JsonApi\Contracts\ProvidesEagerLoads;

/** Batch-load Eloquent relations before JSON:API serialization. */
trait WithEagerIncludes
{
    /** Create a new anonymous resource collection. */
    public static function collection($resource): AnonymousResourceCollection
    {
        $request = JsonApiRequest::createFrom(request());
        $includes = static::getSparseIncludes($request);

        if (! empty($includes)) {
            $models = $resource instanceof Paginator ? $resource->getCollection() : $resource;

            if ($models instanceof EloquentCollection && $models->isNotEmpty()) {
                $fields = static::sparseFieldsForOwnType($models->first(), $request);
                $includes = static::includesWithinFields($includes, $fields, $models->first());

                if (! empty($includes)) {
                    static::loadEagerIncludes($models, $includes, request()->input('filter', []), $fields);
                }
            }
        }

        return parent::collection($resource);
    }

    /** Create an HTTP response that represents the object. */
    public function toResponse($request): JsonResponse
    {
        $jsonApiRequest = JsonApiRequest::createFrom($request);
        $includes = static::getSparseIncludes($jsonApiRequest);

        if (! empty($includes) && $this->resource instanceof Model) {
            $fields = static::sparseFieldsForOwnType($this->resource, $jsonApiRequest);
            $includes = static::includesWithinFields($includes, $fields, $this->resource);

            if (! empty($includes)) {
                static::loadEagerIncludes(EloquentCollection::make([$this->resource]), $includes, $request->input('filter', []), $fields);
            }
        }

        return parent::toResponse($request);
    }

    /**
     * Relationships of this resource that a sparse fieldset lets through.
     *
     * Laravel applies fields[type] to attributes only. The JSON:API spec makes a fieldset cover
     * relationships too, so a relationship the fieldset doesn't name is dropped here, and with it
     * its `included` resources. Relations a name addresses (see fieldRelationsOf) stay.
     * A resource with no fieldset keeps every requested relationship.
     */
    protected function requestedResourceRelationships(JsonApiRequest $request, ?string $relationName = null): array
    {
        $requested = parent::requestedResourceRelationships($request, $relationName);

        if ($relationName !== null || ! $this->usesRequestQueryString) {
            return $requested;
        }

        $type = $this->resolveResourceType($request);

        if (! $request->hasSparseFieldset($type)) {
            return $requested;
        }

        $fields = $request->sparseFields($type);

        return array_values(array_intersect($requested, static::relationsWithinFields($fields, $this->resource)));
    }

    /**
     * Resource attributes, minus the fields that address a relation's rows instead
     * (an EAV code is served through `attribute_values`, not repeated as an attribute).
     */
    protected function resolveResourceAttributes(JsonApiRequest $request, string $resourceType): array
    {
        $attributes = parent::resolveResourceAttributes($request, $resourceType);

        if (! $this->usesRequestQueryString || ! $request->hasSparseFieldset($resourceType)) {
            return $attributes;
        }

        $addressed = array_merge(...array_values(static::fieldRelationsOf($this->resource, $request->sparseFields($resourceType))));

        return array_diff_key($attributes, array_flip($addressed));
    }

    /**
     * Drop the includes a sparse fieldset doesn't name, so relations that won't be
     * serialized aren't loaded either. Null means no fieldset was requested.
     *
     * @param  array<string, mixed>  $includes
     * @param  list<string>|null  $fields
     * @return array<string, mixed>
     */
    protected static function includesWithinFields(array $includes, ?array $fields, mixed $model = null): array
    {
        return $fields === null ? $includes : array_intersect_key($includes, array_flip(static::relationsWithinFields($fields, $model)));
    }

    /**
     * Relations a fieldset keeps: the ones it names, and the ones its names address.
     *
     * @param  list<string>  $fields
     * @return list<string>
     */
    protected static function relationsWithinFields(array $fields, mixed $model): array
    {
        return array_values(array_unique([...$fields, ...array_keys(static::fieldRelationsOf($model, $fields))]));
    }

    /**
     * Ask the model which relations the fieldset's names address, through its optional
     * `public static fieldRelations(array $fields): array` — duck-typed, like loadIncludedRelations(),
     * so neither side depends on the package that supplies the names (e.g. EAV attribute codes
     * selecting rows of `attribute_values`).
     *
     * @param  list<string>  $fields
     * @return array<string, list<string>>  Relation => the names among $fields that address its rows.
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

    /** Load the requested includes into the given model collection. */
    protected static function loadEagerIncludes(EloquentCollection $models, array $includes, array $filter = [], ?array $fields = null): void
    {
        $template = $models->first();

        if (! empty($filter)) {
            static::loadIncludedRelations($models, $template, $filter);
        }

        $tree = static::buildRelationTree($includes, $template);

        static::validateRelationTree($template, $tree);
        static::loadProvidedEagerLoads($models, $template, array_keys($includes), $fields);
        static::loadRelationLevel($models, $template, $tree);
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

        if ($relations !== []) {
            $models->loadMissing($relations);
        }
    }

    /** Build a nested relation tree, applying eager overrides. */
    protected static function buildRelationTree(array $includes, Model $owner): array
    {
        $overrides = method_exists($owner, 'eagerRelations') ? $owner::eagerRelations() : [];
        $tree = [];

        foreach ($includes as $relation => $nested) {
            $nestedRelations = array_values(array_filter((array) $nested));

            if (isset($overrides[$relation])) {
                $prefix = $relation.'.';
                $deepIncludes = array_map(
                    fn (string $path) => str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path,
                    $overrides[$relation]
                );

                $nestedRelations = empty($nestedRelations)
                    ? $deepIncludes
                    : array_values(array_unique(array_merge($deepIncludes, $nestedRelations)));
            }

            $tree[$relation] = static::buildTreeFromPaths($nestedRelations);
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
    protected static function loadRelationLevel(EloquentCollection $models, Model $template, array $tree): void
    {
        if ($models->isEmpty() || empty($tree)) {
            return;
        }

        $loaded = $template->getRelations();

        foreach ($tree as $relation => $children) {
            if (! array_key_exists($relation, $loaded)) {
                $models->loadMissing($relation);
            }

            $relatedTemplate = $template->{$relation}()->getRelated();
            $relatedModels = EloquentCollection::make($models->pluck($relation)->flatten(1)->filter());

            static::loadProvidedEagerLoads($relatedModels, $relatedTemplate, array_keys($children));

            if (! empty($children)) {
                static::loadRelationLevel($relatedModels, $relatedTemplate, $children);
            }
        }
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
