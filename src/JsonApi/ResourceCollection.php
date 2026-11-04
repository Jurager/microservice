<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

class ResourceCollection extends AnonymousResourceCollection
{
    private ?DocumentRenderer $document = null;

    public function prepareDocument(Request $request): void
    {
        $this->document = new DocumentRenderer($this->collection, $request);
    }

    public function toAttributes(Request $request): array
    {
        return $this->document($request)->data();
    }

    public function with($request): array
    {
        return array_filter([
            'included' => $this->document($request)->included(),
            'jsonapi' => JsonApiResource::$jsonApiInformation,
        ]);
    }

    public function toResponse($request): JsonResponse
    {
        $this->document($request);

        return parent::toResponse($request);
    }

    private function document(Request $request): DocumentRenderer
    {
        if ($this->document === null || ! $this->document->matches($request)) {
            $this->prepareDocument($request);
        }

        return $this->document;
    }
}
