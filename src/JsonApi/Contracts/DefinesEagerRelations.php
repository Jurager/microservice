<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi\Contracts;

/**
 * Define nested relations to load with requested includes.
 */
interface DefinesEagerRelations
{
    /**
     * Nested relations to load with each relation, keyed by the relation name.
     *
     * @return array<string, list<string>>
     */
    public static function eagerRelations(): array;
}
