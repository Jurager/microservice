<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\JsonApi\JsonApiRequest;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Jurager\Microservice\JsonApi\Concerns\WithEagerIncludes;

/** The resource views and include paths prepared for one compound document. */
class IncludePlan
{
    private array $views = [];

    private ?array $paths = null;

    private bool $compatible = true;

    public function __construct(private readonly JsonApiRequest $request) {}

    public function root(JsonApiResource $resource, array $relations, Collection $models, bool $usesQueryString): void
    {
        $this->paths = array_keys(Arr::dot($relations));
        $this->record($resource, $relations, $models, $usesQueryString, primary: true);
    }

    public function add(?JsonApiResource $resource, array $relations, Collection $models, bool $usesQueryString): void
    {
        $this->record($resource, $relations, $models, $usesQueryString, primary: false);
    }

    private function record(?JsonApiResource $resource, array $relations, Collection $models, bool $usesQueryString, bool $primary): void
    {
        if (! $resource || ! $resource->resource instanceof Model || ! $usesQueryString
            || ! in_array(WithEagerIncludes::class, class_uses_recursive($resource), true)
            || $models->contains(fn (Model $related) => $related::class !== $resource->resource::class)) {
            $this->compatible = false;

            return;
        }

        $key = $resource->resource::class.':'.$resource->resolveResourceType($this->request);
        $view = [$resource::class, $relations, $primary];

        if (isset($this->views[$key]) && $this->views[$key] !== $view) {
            $this->compatible = false;
        }

        $this->views[$key] = $view;
    }

    public function paths(): ?array
    {
        return $this->paths;
    }

    public function requireSeparateTraversal(): void
    {
        $this->compatible = false;
    }

    public function resolve(Collection $resources, JsonApiRequest $request): Collection
    {
        if (! $this->compatible || $this->paths === null || $resources->contains(fn ($resource) => $resource->loadedRelationshipsMap === null)) {
            return $resources->flatMap(fn ($resource) => $resource->resolveIncludedResourceObjects($request));
        }

        if ($resources->isEmpty()) {
            return new Collection;
        }

        // Laravel appends descendants during traversal; keep the primary resource's map intact.
        $root = clone $resources->first();
        $root->loadedRelationshipsMap = $resources->flatMap(fn ($resource) => $resource->loadedRelationshipsMap)->all();

        return $root->resolveIncludedResourceObjects($request);
    }
}
