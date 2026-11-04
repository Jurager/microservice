<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\JsonApi\JsonApiRequest;
use Illuminate\Support\Collection;
use Jurager\Microservice\JsonApi\DocumentRenderer;
use Jurager\Microservice\JsonApi\ResourceCollection;

/**
 * Batch-load the includes of JSON:API resources and render each resource of the document once.
 */
trait WithEagerIncludes
{
    private ?DocumentRenderer $jsonApiDocument = null;

    protected static function newCollection($resource): AnonymousResourceCollection
    {
        return new ResourceCollection($resource, static::class);
    }

    public static function collection($resource): AnonymousResourceCollection
    {
        $collection = parent::collection($resource);
        $collection->prepareDocument(request());

        return $collection;
    }

    public function toResponse($request): JsonResponse
    {
        $this->jsonApiDocument = new DocumentRenderer([$this], $request);

        return parent::toResponse($request);
    }

    public function resolveResourceData(Request $request): array
    {
        return $this->jsonApiDocument($request)->data()[0];
    }

    public function with($request): array
    {
        return array_filter([
            'included' => $this->jsonApiDocument($request)->included(),
            'jsonapi' => static::$jsonApiInformation,
        ]);
    }

    public function resolveIncludedResourceObjects(JsonApiRequest $request): Collection
    {
        return new Collection(array_map(static fn (array $object): array => [
            ...$object, '_uniqueKey' => $object['type'].':'.$object['id'],
        ], $this->jsonApiDocument($request)->included()));
    }

    private function jsonApiDocument(Request $request): DocumentRenderer
    {
        if ($this->jsonApiDocument === null || ! $this->jsonApiDocument->matches($request)) {
            $this->jsonApiDocument = new DocumentRenderer([$this], $request);
        }

        return $this->jsonApiDocument;
    }
}
