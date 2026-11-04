<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi\Contracts;

use Closure;

interface ProvidesEagerLoads
{
    /**
     * Get additional eager loads, even when no includes are requested.
     *
     * @param  list<string>  $included  Requested relationships.
     * @param  list<string>|null  $fields  Requested sparse fields for the resource type.
     * @return array<int|string, string|Closure>
     */
    public function eagerLoads(array $included, ?array $fields = null): array;
}
