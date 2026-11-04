<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Http\Request;

/**
 * Represents a JSON:API single-resource response.
 *
 * @template T of Item
 *
 * @extends Document<T>
 */
class ItemDocument extends Document
{
    /** @var T */
    private Item $item;

    /** @param  class-string<T>  $itemClass */
    public function __construct(array $body, string $itemClass = Item::class)
    {
        parent::__construct($body, $itemClass);

        $this->item = Item::from($body['data'] ?? [], $this->itemClass);

        $this->included->registerPrimary([$this->item]);
        $this->included->autoAttach($this->item);
    }

    /**
     * Build an empty document without a network round trip.
     *
     * @template TItem of Item
     *
     * @param  class-string<TItem>  $itemClass
     * @return static<TItem>
     */
    public static function empty(string $itemClass = Item::class): static
    {
        return new static([], $itemClass);
    }

    /** @return T */
    public function data(): Item
    {
        return $this->item;
    }

    protected function roots(): array
    {
        return [$this->item];
    }

    protected function serializeData(?callable $transform, ?Request $request): array
    {
        return $this->serializeItem($this->item, $transform, $request);
    }
}
