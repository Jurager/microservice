<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;

class ResourceCollection extends AnonymousResourceCollection
{
    private ?DocumentRenderer $document = null;

    public function prepareDocument(Request $request): void
    {
        $this->document = new DocumentRenderer($this->collection, $request);
    }

    public function toAttributes(Request $request): array
    {
        return $this->renderer($request)->data();
    }

    public function with($request): array
    {
        return array_filter([
            'included' => $this->renderer($request)->included(),
            'jsonapi' => JsonApiResource::$jsonApiInformation,
        ]);
    }

    public function toResponse($request): JsonResponse
    {
        $this->renderer($request);

        return parent::toResponse($request);
    }

    /**
     * Get the JSON:API document as an array.
     */
    public function document(Request $request): array
    {
        $this->renderer($request);
        $request = $this->resolveJsonApiRequestFrom($request);

        if ($this->resource instanceof AbstractPaginator || $this->resource instanceof AbstractCursorPaginator) {
            if ($this->preserveAllQueryParameters) {
                $this->resource->appends($request->query());
            } elseif ($this->queryParameters !== null) {
                $this->resource->appends($this->queryParameters);
            }
        }

        return (new ResourceDocument($this))->resolve($request);
    }

    private function renderer(Request $request): DocumentRenderer
    {
        if ($this->document === null || ! $this->document->matches($request)) {
            $this->prepareDocument($request);
        }

        return $this->document;
    }
}
