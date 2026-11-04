<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi\Contracts;

/**
 * Map sparse fields to the relations that provide their values.
 */
interface MapsFieldsToRelations
{
    /**
     * Group the requested fields that a relation serves, by relation name.
     *
     * @param  list<string>  $fields
     * @return array<string, list<string>>
     */
    public static function fieldRelations(array $fields): array;
}
