<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi\Concerns;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
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
                $first = $models->first();

                [$toLoad, $nested] = static::classifyIncludes($first, $sparseIncluded);

                if ($toLoad) {
                    $models->loadMissing(static::buildEagerLoad($toLoad, $first));
                }

                foreach ($nested as $relation => $subs) {
                    $models->loadMissing(array_map(fn ($sub) => "$relation.$sub", $subs));
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

        if ($sparseIncluded && $this->resource instanceof Model) {
            $model = $this->resource;

            [$toLoad, $nested] = static::classifyIncludes($model, $sparseIncluded);

            if ($toLoad) {
                $model->loadMissing(static::buildEagerLoad($toLoad, $model));
            }

            foreach ($nested as $relation => $subs) {
                $model->loadMissing(array_map(fn ($sub) => "$relation.$sub", $subs));
            }
        }

        return parent::toResponse($request);
    }

    protected static function classifyIncludes(Model $first, array $sparseIncluded): array
    {
        $loaded = $first->getRelations();
        $toLoad = [];
        $nested = [];

        foreach ($sparseIncluded as $relation => $subRelations) {
            if (! $first->isRelation($relation)) {
                abort(400, "Unknown include: \"$relation\".");
            }

            $subs = array_values(array_filter((array) $subRelations));

            if ($subs) {
                $related = $first->$relation()->getRelated();
                foreach ($subs as $sub) {
                    if (! $related->isRelation($sub)) {
                        abort(400, "Unknown include: \"{$relation}.{$sub}\".");
                    }
                }
            }

            if (isset($loaded[$relation]) && $subs) {
                $nested[$relation] = $subs;
            } elseif (! isset($loaded[$relation])) {
                $toLoad[$relation] = $subRelations;
            }
        }

        return [$toLoad, $nested];
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
