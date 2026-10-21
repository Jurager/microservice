<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Http\Resources\JsonApi\JsonApiRequest;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Arr;

class ResourceCollection extends AnonymousResourceCollection
{
    private ?IncludePlan $includePlan = null;

    public function withIncludePlan(IncludePlan $plan): static
    {
        $this->includePlan = $plan;

        return $this;
    }

    public function with($request)
    {
        if ($this->includePlan === null) {
            return parent::with($request);
        }

        return array_filter([
            'included' => $this->includePlan->resolve($this->collection, JsonApiRequest::createFrom($request))
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
