<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi\Concerns;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\JsonApi\JsonApiRequest;

/**
 * Batch-load Eloquent relations before JSON:API serialization.
 */
trait WithEagerIncludes
{
    public static function collection($resource): AnonymousResourceCollection
    {
        $jsonApiRequest = JsonApiRequest::createFrom(request());
        $relations      = $jsonApiRequest->sparseIncluded() ?? [];
        $sparseIncluded = array_combine(
            $relations,
            array_map(fn ($r) => $jsonApiRequest->sparseIncluded($r), $relations)
        );

        if ($sparseIncluded) {
            $models = $resource instanceof Paginator
                ? $resource->getCollection()
                : $resource;

            if ($models instanceof EloquentCollection && $models->isNotEmpty()) {
                $first  = $models->first();
                $loaded = $first->getRelations();
                $toLoad = [];
                $nested = [];

                foreach ($sparseIncluded as $relation => $subRelations) {
                    $subs = array_values(array_filter((array) $subRelations));

                    if (isset($loaded[$relation]) && $subs) {
                        $nested[$relation] = $subs;
                    } elseif (! isset($loaded[$relation])) {
                        $toLoad[$relation] = $subRelations;
                    }
                }

                if ($toLoad) {
                    foreach (array_keys($toLoad) as $relation) {
                        if (! $first->isRelation($relation)) {
                            abort(400, "Unknown include: \"$relation\".");
                        }
                    }

                    $models->loadMissing(static::buildEagerLoad($toLoad, $first));
                }

                foreach ($nested as $relation => $subs) {
                    $allRelated = new EloquentCollection();

                    foreach ($models as $model) {
                        $related = $model->getRelation($relation);

                        if ($related instanceof EloquentCollection) {
                            $allRelated = $allRelated->merge($related);
                        } elseif ($related !== null) {
                            $allRelated->push($related);
                        }
                    }

                    if ($allRelated->isNotEmpty()) {
                        $firstRelated = $allRelated->first();

                        foreach ($subs as $sub) {
                            if (! $firstRelated->isRelation($sub)) {
                                abort(400, "Unknown include: \"{$relation}.{$sub}\".");
                            }
                        }

                        $allRelated->loadMissing($subs);
                    }
                }
            }
        }

        return parent::collection($resource);
    }

    public function toResponse($request): JsonResponse
    {
        $jsonApiRequest = JsonApiRequest::createFrom($request);
        $relations      = $jsonApiRequest->sparseIncluded() ?? [];
        $sparseIncluded = array_combine(
            $relations,
            array_map(fn ($r) => $jsonApiRequest->sparseIncluded($r), $relations)
        );

        if ($sparseIncluded && $this->resource instanceof \Illuminate\Database\Eloquent\Model) {
            $model  = $this->resource;
            $loaded = $model->getRelations();
            $toLoad = [];
            $nested = [];

            foreach ($sparseIncluded as $relation => $subRelations) {
                $subs = array_values(array_filter((array) $subRelations));

                if (isset($loaded[$relation]) && $subs) {
                    $nested[$relation] = $subs;
                } elseif (! isset($loaded[$relation])) {
                    $toLoad[$relation] = $subRelations;
                }
            }

            if ($toLoad) {
                foreach (array_keys($toLoad) as $relation) {
                    if (! $model->isRelation($relation)) {
                        abort(400, "Unknown include: \"$relation\".");
                    }
                }

                $model->loadMissing(static::buildEagerLoad($toLoad, $model));
            }

            foreach ($nested as $relation => $subs) {
                $related    = $model->getRelation($relation);
                $allRelated = $related instanceof EloquentCollection
                    ? $related
                    : new EloquentCollection($related !== null ? [$related] : []);

                if ($allRelated->isNotEmpty()) {
                    $firstRelated = $allRelated->first();

                    foreach ($subs as $sub) {
                        if (! $firstRelated->isRelation($sub)) {
                            abort(400, "Unknown include: \"{$relation}.{$sub}\".");
                        }
                    }

                    $allRelated->loadMissing($subs);
                }
            }
        }

        return parent::toResponse($request);
    }

    protected static function buildEagerLoad(array $sparseIncluded, object $first): array
    {
        $overrides = method_exists($first, 'eagerRelations') ? $first::eagerRelations() : [];

        $load = [];

        foreach ($sparseIncluded as $relation => $nested) {
            if (isset($overrides[$relation])) {
                $prefix = $relation.'.';
                $deep   = array_map(
                    fn ($path) => str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path,
                    $overrides[$relation]
                );
                $load[$relation] = fn ($q) => $q->with($deep);
                continue;
            }

            $subs = array_values(array_filter((array) $nested, fn ($s) => $s !== null));

            $subs
                ? $load[$relation] = fn ($q) => $q->with($subs)
                : $load[] = $relation;
        }

        return $load;
    }
}
