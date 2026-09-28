<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi\Contracts;

interface ProvidesEagerLoads
{
    /**
     * Relations to eager-load alongside the requested includes.
     *
     * Called for every serialized model, also when nothing was included, so a model can
     * declare relations its resource always reads. It only ever sees the request through
     * these two arguments.
     *
     * @param list<string> $included
     * @param list<string>|null $fields Sparse fields requested for this resource's own type
     *                                  (fields[type]=...), or null when none were requested.
     * @return array<int|string, string|\Closure> Plain relation names, or relation => constraint
     *                                             closure pairs, exactly as accepted by loadMissing().
     */
    public function eagerLoads(array $included, ?array $fields = null): array;
}
