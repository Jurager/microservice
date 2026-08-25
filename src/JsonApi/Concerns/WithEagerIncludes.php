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
        $includes = static::getSparseIncludes(JsonApiRequest::createFrom(request()));

        if (! empty($includes)) {
            $models = $resource instanceof Paginator ? $resource->getCollection() : $resource;

            if ($models instanceof EloquentCollection && $models->isNotEmpty()) {
                static::loadEagerIncludes($models, $includes, request()->input('filter', []));
            }
        }

        return parent::collection($resource);
    }

    /** Create an HTTP response that represents the object. */
    public function toResponse($request): JsonResponse
    {
        $includes = static::getSparseIncludes(JsonApiRequest::createFrom($request));

        if (! empty($includes) && $this->resource instanceof Model) {
            static::loadEagerIncludes(EloquentCollection::make([$this->resource]), $includes, $request->input('filter', []));
        }

        return parent::toResponse($request);
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
     */
    protected static function loadEagerIncludes(EloquentCollection $models, array $includes, array $filter = []): void
    {
        $template = $models->first();

        if (! empty($filter) && method_exists($template, 'loadIncludedRelations')) {
            foreach ($models as $model) {
                $model->loadIncludedRelations($filter);
            }
        }

        $tree = static::buildRelationTree($includes, $template);

        static::validateRelationTree($template, $tree);
        static::loadRelationLevel($models, $template, $tree);

        if ($template instanceof ProvidesEagerLoads) {
            $relations = $template->eagerLoads(array_keys($includes));

            if ($relations !== []) {
                $models->loadMissing($relations);
            }
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

            if (! empty($children)) {
                $relatedTemplate = $template->{$relation}()->getRelated();
                $relatedModels = EloquentCollection::make($models->pluck($relation)->flatten(1)->filter());

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
