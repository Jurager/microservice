<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Closure;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A JSON:API document received from another service.
 *
 * @template T of Item
 */
abstract class Document implements Responsable
{
    protected array $meta;

    protected array $links;

    protected Includes $included;

    /** @var (Closure(T): array)|null What through() set. */
    protected ?Closure $transform = null;

    /** @param  class-string<T>  $itemClass */
    public function __construct(array $body, protected readonly string $itemClass = Item::class)
    {
        $this->meta = $body['meta'] ?? [];
        $this->links = $body['links'] ?? [];
        $this->included = new Includes($body['included'] ?? []);
    }

    /**
     * The primary resources of the document.
     *
     * @return list<T>
     */
    abstract protected function roots(): array;

    /**
     * The `data` member of the document.
     *
     * @param  (callable(T): array)|null  $transform
     */
    abstract protected function serializeData(?callable $transform, ?Request $request): array;

    public function meta(): array
    {
        return $this->meta;
    }

    public function links(): array
    {
        return $this->links;
    }

    public function included(): Includes
    {
        return $this->included;
    }

    public function rawIncluded(): array
    {
        return $this->included->raw();
    }

    /**
     * Resolve included relationships and attach them to every primary resource.
     *
     * @param  array<string, class-string<Item>|array{0: class-string<Item>, 1: array}>  $map
     */
    public function withRelations(array $map): static
    {
        foreach ($this->roots() as $item) {
            $this->included->attachRelations($item, $map);
        }

        return $this;
    }

    /**
     * Keep only the included resources the filter accepts, unlinking the rest.
     */
    public function filterIncluded(callable $filter): static
    {
        $this->included->applyPolicy(static fn (array $resource): ?array => $filter($resource) ? $resource : null);

        return $this->withoutOrphans();
    }

    /**
     * Replace every included resource with what the transformer returns for it.
     */
    public function transformIncluded(callable $transformer): static
    {
        $this->included->applyPolicy($transformer);

        return $this;
    }

    /**
     * Pass every included resource through a policy, dropping those it returns null for.
     */
    public function applyPolicy(callable $policy): static
    {
        $this->included->applyPolicy($policy);
        $this->included->pruneUnreachable($this->roots());

        return $this;
    }

    /**
     * Remove relationship links that reference resources no longer in `included`.
     */
    public function withoutOrphans(): static
    {
        foreach ($this->roots() as $item) {
            $item->withoutOrphanLinks($this->included);
        }

        return $this;
    }

    /**
     * Transform primary resources when rendering the response.
     *
     * @param  callable(T): array  $transform
     */
    public function through(callable $transform): static
    {
        $this->transform = $transform(...);

        return $this;
    }

    /**
     * Render the document with its policies, transforms, and rewritten links.
     */
    public function toResponse($request = null): JsonResponse
    {
        if ($request instanceof Request && ($policy = $request->attributes->get(Includes::class)) && ! $this->included->isEmpty()) {
            $this->applyPolicy($policy);
        }

        $request = $request instanceof Request ? $request : null;
        $body = ['data' => $this->serializeData($this->transform, $request)];

        if ($included = $this->included->raw()) {
            $body['included'] = $included;
        }

        if ($links = $this->responseLinks($request)) {
            $body['links'] = $links;
        }

        if ($this->meta) {
            $body['meta'] = $this->meta;
        }

        return new JsonResponse($body, 200, ['Content-Type' => 'application/vnd.api+json']);
    }

    /**
     * A primary resource as rendered: through the transform, its links pointing at the request.
     */
    protected function serializeItem(Item $item, ?callable $transform, ?Request $request): array
    {
        if ($transform !== null) {
            return $transform($item);
        }

        $resource = $item->toArray();

        if ($request !== null && isset($resource['links'])) {
            $resource['links'] = LinkRewriter::rewriteAll($resource['links'], $request);
        }

        return $resource;
    }

    /**
     * Document links as forwarded to the client.
     */
    protected function responseLinks(?Request $request): array
    {
        return $this->links;
    }
}
