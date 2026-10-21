<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Http\Resources\JsonApi\JsonApiRequest;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Arr;

class ResourceCollection extends AnonymousResourceCollection
{
    public function with($request)
    {
        $first = $this->collection->first();

        return array_filter([
            'included' => $first?->resolveCollectionIncludedResourceObjects(JsonApiRequest::createFrom($request), $this->collection)
                ->uniqueStrict('_uniqueKey')
                ->map(fn (array $resource) => Arr::except($resource, ['_uniqueKey']))
                ->values()
                ->all(),
            ...($implementation = JsonApiResource::$jsonApiInformation)
                ? ['jsonapi' => $implementation]
                : [],
        ]);
    }
}
