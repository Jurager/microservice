<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi\Concerns;

use Closure;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\JsonApi\JsonApiRequest;

/**
 * Batch-load Eloquent relations before JSON:API serialization.
 *
 * Relations are loaded one nesting level at a time, driven by the requested
 * include paths. A model may narrow what an eager-loaded relation actually
 * returns (e.g. a lookup table that must be scoped to the values actually
 * referenced by already-loaded rows, instead of returning everything it
 * contains) by declaring:
 *
 *     public static function eagerConstraints(): array
 *     {
 *         return [
 *             'enums' => [self::class, 'constrainEnums'],
 *         ];
 *     }
 *
 * The callable receives (Relation $query, EloquentCollection $root, string $path).
 * $query is the relation about to be eager-loaded (as Eloquent passes it to any
 * `with()` constraint closure — a Relation instance, not a plain Builder, though
 * it forwards query-builder methods like whereIn/where). $root is the top-level
 * collection being serialized and $path is the full dotted include path leading
 * to this relation (e.g. "attribute_values.attribute.enums"). This applies
 * wherever the relation is reached, regardless of nesting depth or which
 * resource requested it — the constrainer typically walks $root via $path to
 * find the ancestor rows it needs (see Jurager\Eav\Models\Attribute::enums()
 * for a concrete example).
 *
 * Loading happens strictly level-by-level (not as one nested with() call):
 * Eloquent resolves nested with() closures bottom-up, so an ancestor relation
 * isn't attached to $root yet while a deeper constraint closure is still
 * executing — a constrainer reading $root at that point would see stale data.
 *
 * A model may also skip the query entirely for owners that structurally can
 * never have the relation (e.g. a text attribute never has enum options, so
 * there's no reason to even ask), by declaring:
 *
 *     public static function eagerApplicable(): array
 *     {
 *         return [
 *             'enums' => [self::class, 'hasEnumOptions'],
 *         ];
 *     }
 *
 * The callable receives a single owner Model and returns bool. Owners it
 * rejects never hit the database for that relation — they get the relation's
 * native empty value (Eloquent's own Relation::initRelation()), same as if
 * the query had legitimately found nothing for them.
 */
trait WithEagerIncludes
{
    public static function collection($resource): AnonymousResourceCollection
    {
        $sparseIncluded = self::sparseIncludeMap(JsonApiRequest::createFrom(request()));

        if ($sparseIncluded) {
            $models = $resource instanceof Paginator ? $resource->getCollection() : $resource;

            if ($models instanceof EloquentCollection && $models->isNotEmpty()) {
                static::loadIncludes($models, $models->first(), $sparseIncluded);
            }
        }

        return parent::collection($resource);
    }

    public function toResponse($request): JsonResponse
    {
        $sparseIncluded = self::sparseIncludeMap(JsonApiRequest::createFrom($request));

        if ($sparseIncluded && $this->resource instanceof Model) {
            static::loadIncludes(EloquentCollection::make([$this->resource]), $this->resource, $sparseIncluded);
        }

        return parent::toResponse($request);
    }

    private static function sparseIncludeMap(JsonApiRequest $request): array
    {
        $relations = $request->sparseIncluded() ?? [];

        return $relations
            ? array_combine($relations, array_map(fn ($r) => $request->sparseIncluded($r), $relations))
            : [];
    }

    private static function loadIncludes(EloquentCollection $models, Model $first, array $sparseIncluded): void
    {
        $tree = static::buildTree($sparseIncluded, $first);

        static::loadLevel($models, $first, $tree, $models, '');
    }

    /**
     * Turn the raw sparse-include map (relation => flat dotted sub-paths) into
     * a nested tree, applying the owner model's `eagerRelations()` overrides.
     */
    protected static function buildTree(array $sparseIncluded, Model $owner): array
    {
        $overrides = method_exists($owner, 'eagerRelations') ? $owner::eagerRelations() : [];

        $tree = [];

        foreach ($sparseIncluded as $relation => $nested) {
            if (! $owner->isRelation($relation)) {
                abort(400, "Unknown include: \"$relation\".");
            }

            $subs = array_values(array_filter((array) $nested));

            if (isset($overrides[$relation])) {
                $prefix = $relation.'.';
                $deep = array_map(
                    fn ($path) => str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path,
                    $overrides[$relation]
                );

                $subs = $subs ? array_values(array_unique(array_merge($deep, $subs))) : $deep;
            }

            $tree[$relation] = static::pathsToTree($subs);
        }

        return $tree;
    }

    /**
     * Load one level of $tree onto $owners (all instances of $ownerTemplate's
     * class), then recurse into each relation's own subtree once its data has
     * actually landed on $owners.
     */
    private static function loadLevel(EloquentCollection $owners, Model $ownerTemplate, array $tree, EloquentCollection $root, string $prefix): void
    {
        if ($owners->isEmpty() || ! $tree) {
            return;
        }

        $loaded = $ownerTemplate->getRelations();

        foreach ($tree as $relation => $children) {
            if (! $ownerTemplate->isRelation($relation)) {
                abort(400, "Unknown include: \"{$prefix}{$relation}\".");
            }

            $path = $prefix.$relation;

            if (! isset($loaded[$relation])) {
                $applicable = static::eagerApplicable($ownerTemplate, $relation);
                $targets = $owners;

                if ($applicable) {
                    $targets = $owners->filter($applicable);
                    $skipped = $owners->reject($applicable);

                    if ($skipped->isNotEmpty()) {
                        $ownerTemplate->$relation()->initRelation($skipped->all(), $relation);
                    }
                }

                if ($targets->isNotEmpty()) {
                    $constraint = static::eagerConstraint($ownerTemplate, $relation);

                    $targets->loadMissing([
                        $relation => $constraint ? fn ($query) => $constraint($query, $root, $path) : fn ($query) => $query,
                    ]);
                }
            }

            if ($children) {
                $related = $ownerTemplate->$relation()->getRelated();
                $next = EloquentCollection::make($owners->pluck($relation)->flatten(1)->filter());

                static::loadLevel($next, $related, $children, $root, "{$path}.");
            }
        }
    }

    /**
     * @return array<string, array> a tree of dotted paths, e.g.
     *   ['attribute.type', 'attribute.enums.translations'] becomes
     *   ['attribute' => ['type' => [], 'enums' => ['translations' => []]]]
     */
    private static function pathsToTree(array $paths): array
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

    private static function eagerConstraint(Model $owner, string $relation): ?Closure
    {
        if (! method_exists($owner, 'eagerConstraints')) {
            return null;
        }

        $constraint = $owner::eagerConstraints()[$relation] ?? null;

        return $constraint ? Closure::fromCallable($constraint) : null;
    }

    private static function eagerApplicable(Model $owner, string $relation): ?Closure
    {
        if (! method_exists($owner, 'eagerApplicable')) {
            return null;
        }

        $applicable = $owner::eagerApplicable()[$relation] ?? null;

        return $applicable ? Closure::fromCallable($applicable) : null;
    }
}
