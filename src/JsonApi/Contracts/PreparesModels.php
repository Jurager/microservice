<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Prepare the models of a resource for the request, a level of the document at a time.
 */
interface PreparesModels
{
    /**
     * Load what the resource reads from the request onto the given models; each model is passed once.
     *
     * @param  Collection<int, Model>  $models
     */
    public static function prepareModels(Collection $models, Request $request): void;
}
