<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\PaginatedResourceResponse;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;

/** @internal Build a resource document without an HTTP response. */
class ResourceDocument extends PaginatedResourceResponse
{
    public function resolve(Request $request): array
    {
        $data = $this->resource->resolve($request);
        $with = $this->resource->with($request);

        if ($this->resource->resource instanceof AbstractPaginator || $this->resource->resource instanceof AbstractCursorPaginator) {
            return $this->wrap($data, array_merge_recursive(
                $this->paginationInformation($request), $with, $this->resource->additional,
            ));
        }

        return $this->wrap($data, $with, $this->resource->additional);
    }
}
