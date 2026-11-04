<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Http\Resources\PotentiallyMissing;
use JsonSerializable;
use Jurager\Microservice\JsonApi\Contracts\MapsFieldsToRelations;
use Jurager\Microservice\Tracing\RequestTiming;
use stdClass;

/**
 * Renders the data and included resources of a compound document, each resource identity once.
 */
class DocumentRenderer
{
    private ?IncludeLoader $loader;

    /** @var list<ResourceNode> */
    private array $roots;

    private ?array $data = null;

    private array $included = [];

    private array $members = [];

    private object $token;

    private array $input;

    private ?RequestTiming $timing;

    /** @param  iterable<JsonApiResource>  $resources */
    public function __construct(iterable $resources, Request $request)
    {
        $started = hrtime(true);

        $this->token = $request->attributes->get(self::class) ?? new stdClass;
        $request->attributes->set(self::class, $this->token);
        $this->input = [$request->input(), $request->header('Accept-Language')];
        $timing = app(RequestTiming::class);
        $this->timing = $timing->enabled() ? $timing : null;

        $this->loader = new IncludeLoader($request);
        $this->roots = $this->loader->load($resources);

        $this->timing?->add('jsonapi_load', (hrtime(true) - $started) / 1e6);
    }

    public function matches(Request $request): bool
    {
        return $request->attributes->get(self::class) === $this->token
            && [$request->input(), $request->header('Accept-Language')] === $this->input;
    }

    public function data(): array
    {
        $this->render();

        return $this->data;
    }

    public function included(): array
    {
        $this->render();

        return $this->included;
    }

    private function render(): void
    {
        if ($this->data !== null) {
            return;
        }

        $started = hrtime(true);
        $primary = [];

        foreach ($this->roots as $node) {
            $primary[$node->key] = true;
        }

        $objects = [];
        $included = [];
        $rendered = [];
        $queue = $this->roots;

        for ($index = 0; isset($queue[$index]); $index++) {
            $node = $queue[$index];
            unset($queue[$index]);

            if (isset($rendered[spl_object_id($node)])) {
                continue;
            }

            $rendered[spl_object_id($node)] = true;

            foreach ($node->deferred as $name => $resolver) {
                $this->loader->resolve($node, $name, $resolver);
            }

            $object = $this->object($node);

            foreach ($node->relationships as $name => $relationship) {
                $identifiers = [];

                foreach ($relationship['resources'] as $related) {
                    $identifiers[] = ['id' => $related->id, 'type' => $related->type];
                    $queue[] = $related;
                }

                $object['relationships'][$name] = ['data' => $relationship['many'] ? $identifiers : ($identifiers[0] ?? null)];
            }

            if ($node->path === '') {
                $objects[spl_object_id($node)] = $object;
            } elseif (! isset($primary[$node->key])) {
                $included[$node->key] = isset($included[$node->key]) ? Includes::merge($included[$node->key], $object) : $object;
            }
        }

        $this->data = array_map(static fn (ResourceNode $node): array => $objects[spl_object_id($node)], $this->roots);
        $this->included = array_values($included);
        $this->roots = $this->members = [];
        $this->loader = null;

        $this->timing?->add('jsonapi_render', (hrtime(true) - $started) / 1e6);
    }

    private function object(ResourceNode $node): array
    {
        $resource = $node->resource;
        $fields = $this->loader->fields($resource);

        if ($fields !== null && $resource->resource instanceof MapsFieldsToRelations) {
            foreach ($resource->resource::fieldRelations($fields) as $relation => $names) {
                if (array_key_exists($relation, $node->tree)) {
                    $fields = array_values(array_diff($fields, $names));
                }
            }
        }

        $key = $resource::class.'@'.$node->key.'@'.json_encode($fields, JSON_THROW_ON_ERROR);

        if (isset($this->members[$key])) {
            return $this->members[$key];
        }

        $request = $this->loader->request;
        $object = ['id' => $node->id, 'type' => $node->type];

        foreach ([
            'attributes' => self::members($resource->toAttributes($request), $request, $fields, $resource->resource),
            'links' => self::members($resource->toLinks($request), $request),
            'meta' => self::members($resource->toMeta($request), $request),
        ] as $member => $values) {
            if ($values !== []) {
                $object[$member] = $values;
            }
        }

        return $this->members[$key] = $object;
    }

    private static function members(mixed $members, Request $request, ?array $fields = null, mixed $model = null): array
    {
        if ($members instanceof Arrayable) {
            $members = $members->toArray();
        } elseif ($members instanceof JsonSerializable) {
            $members = $members->jsonSerialize();
        }

        $resolved = [];

        foreach ($members as $name => $member) {
            if ($member instanceof MergeValue) {
                $resolved = array_replace($resolved, self::members($member->data, $request, $fields, $model));

                continue;
            }

            if ($member instanceof PotentiallyMissing && $member->isMissing()) {
                continue;
            }

            if (is_int($name) && $model !== null) {
                $name = $member;

                if ($fields !== null && ! in_array($name, $fields, true)) {
                    continue;
                }

                $member = is_array($model) ? ($model[$name] ?? null) : $model->{$name};
            } elseif ($fields !== null && ! in_array($name, $fields, true)) {
                continue;
            }

            $member = value($member, $request);

            if ($member instanceof PotentiallyMissing && $member->isMissing()) {
                continue;
            }

            $resolved[$name] = is_array($member) ? self::members($member, $request) : $member;
        }

        return $resolved;
    }
}
