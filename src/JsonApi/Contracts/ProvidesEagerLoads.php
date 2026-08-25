<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi\Contracts;

interface ProvidesEagerLoads
{
    /**
     * Relations to eager-load alongside the requested includes.
     *
     * @param list<string> $included
     * @return list<string>
     */
    public function eagerLoads(array $included): array;
}
