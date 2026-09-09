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
                static::loadEagerIncludes($models, $includes, request()->input('filter', []), static::sparseFieldsForOwnType($models->first(), $request));
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
            static::loadEagerIncludes(
                EloquentCollection::make([$this->resource]),
                $includes,
                $request->input('filter', []),
                static::sparseFieldsForOwnType($this->resource, $jsonApiRequest),
            );
        }

        return parent::toResponse($request);
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
