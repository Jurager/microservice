<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Closure;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * A resource reached through one include path of a compound document.
 */
final class ResourceNode
{
    public readonly string $key;

    /** @var array<string, array{many: bool, resources: list<ResourceNode>}> */
    public array $relationships = [];

    /** @var array<string, Closure> */
    public array $deferred = [];

    public function __construct(
        public readonly JsonApiResource $resource,
        public readonly string $type,
        public readonly string $id,
        public readonly string $path,
        public readonly array $tree,
    ) {
        $this->key = $type.':'.$id;
    }
}
