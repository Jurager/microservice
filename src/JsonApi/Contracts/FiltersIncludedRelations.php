<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi\Contracts;

use Illuminate\Database\Eloquent\Collection;

/**
 * Constrain included relations using request filters.
 */
interface FiltersIncludedRelations
{
    /**
     * Load the included relations of the given models, constrained by the request's filter.
     *
     * @param  Collection<int, static>  $models
     * @param  array<string, mixed>  $filter
     */
    public static function loadIncludedRelationsForMany(Collection $models, array $filter): void;
}
