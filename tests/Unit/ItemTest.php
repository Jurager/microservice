<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Illuminate\Support\Collection;
use Jurager\Microservice\JsonApi\Item;
use PHPUnit\Framework\TestCase;

class ItemTest extends TestCase
{
    private array $resource = [
        'id'   => '42',
        'type' => 'orders',
        'attributes' => [
            'status' => 'pending',
            'total'  => 99.5,
        ],
        'relationships' => [
            'customer' => ['data' => ['type' => 'customers', 'id' => '7']],
            'lines'    => ['data' => [
                ['type' => 'order-lines', 'id' => '1'],
                ['type' => 'order-lines', 'id' => '2'],
            ]],
        ],
    ];

    public function test_id_and_type_are_set(): void
    {
        $item = new Item($this->resource);
        $this->assertSame('42', $item->id);
        $this->assertSame('orders', $item->type);
    }

    public function test_missing_id_and_type_default_to_empty_string(): void
    {
        $item = new Item([]);
        $this->assertSame('', $item->id);
        $this->assertSame('', $item->type);
    }

    public function test_attribute_returns_value(): void
    {
        $item = new Item($this->resource);
        $this->assertSame('pending', $item->attribute('status'));
        $this->assertSame(99.5, $item->attribute('total'));
    }

    public function test_attribute_returns_default_when_missing(): void
    {
        $item = new Item($this->resource);
        $this->assertNull($item->attribute('missing'));
        $this->assertSame('fallback', $item->attribute('missing', 'fallback'));
    }

    public function test_attribute_supports_dot_notation(): void
    {
        $item = new Item([
            'id' => '1', 'type' => 't',
            'attributes' => ['nested' => ['key' => 'val']],
        ]);
        $this->assertSame('val', $item->attribute('nested.key'));
    }

    public function test_attributes_returns_all(): void
    {
        $item = new Item($this->resource);
        $this->assertSame(['status' => 'pending', 'total' => 99.5], $item->attributes());
    }

    public function test_magic_get_returns_attribute(): void
    {
        $item = new Item($this->resource);
        $this->assertSame('pending', $item->status);
        $this->assertNull($item->missing);
    }

    public function test_magic_isset_checks_attribute(): void
    {
        $item = new Item($this->resource);
        $this->assertTrue(isset($item->status));
        $this->assertFalse(isset($item->missing));
    }

    public function test_relation_ids_returns_int_array_for_to_many(): void
    {
        $item = new Item($this->resource);
        $this->assertSame([1, 2], $item->relationIds('lines'));
    }

    public function test_relation_ids_returns_empty_for_missing(): void
    {
        $item = new Item($this->resource);
        $this->assertSame([], $item->relationIds('unknown'));
    }

    public function test_relation_id_returns_int_for_to_one(): void
    {
        $item = new Item($this->resource);
        $this->assertSame(7, $item->relationId('customer'));
    }

    public function test_relation_id_returns_null_for_missing(): void
    {
        $item = new Item($this->resource);
        $this->assertNull($item->relationId('unknown'));
    }

    public function test_relationship_data_returns_array(): void
    {
        $item = new Item($this->resource);
        $this->assertSame(['type' => 'customers', 'id' => '7'], $item->relationshipData('customer'));
    }

    public function test_relationships_returns_all(): void
    {
        $item = new Item($this->resource);
        $this->assertArrayHasKey('customer', $item->relationships());
        $this->assertArrayHasKey('lines', $item->relationships());
    }

    public function test_set_and_get_resolved_to_one(): void
    {
        $item    = new Item($this->resource);
        $related = new Item(['id' => '7', 'type' => 'customers', 'attributes' => ['name' => 'Alice']]);

        $item->setResolved('customer', [$related]);

        $this->assertTrue($item->hasResolved('customer'));
        $this->assertSame($related, $item->getRelationOne('customer'));
    }

    public function test_get_relation_returns_collection(): void
    {
        $item    = new Item($this->resource);
        $related = new Item(['id' => '1', 'type' => 'order-lines', 'attributes' => []]);

        $item->setResolved('lines', [$related]);

        $collection = $item->getRelation('lines');
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertCount(1, $collection);
    }

    public function test_get_relation_returns_empty_collection_when_not_resolved(): void
    {
        $item = new Item($this->resource);
        $col  = $item->getRelation('customer');
        $this->assertInstanceOf(Collection::class, $col);
        $this->assertTrue($col->isEmpty());
    }

    public function test_get_relation_one_returns_null_when_not_resolved(): void
    {
        $item = new Item($this->resource);
        $this->assertNull($item->getRelationOne('customer'));
    }

    public function test_has_resolved_returns_false_initially(): void
    {
        $item = new Item($this->resource);
        $this->assertFalse($item->hasResolved('customer'));
    }

    public function test_to_array_without_relationships(): void
    {
        $item = new Item(['id' => '1', 'type' => 'orders', 'attributes' => ['status' => 'ok']]);
        $arr  = $item->toArray();

        $this->assertSame('1', $arr['id']);
        $this->assertSame('orders', $arr['type']);
        $this->assertArrayNotHasKey('relationships', $arr);
    }

    public function test_to_array_includes_relationships_when_present(): void
    {
        $item = new Item($this->resource);
        $this->assertArrayHasKey('relationships', $item->toArray());
    }

    public function test_to_response_returns_json_response_with_data(): void
    {
        $item     = new Item(['id' => '1', 'type' => 'orders', 'attributes' => []]);
        $response = $item->toResponse();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('1', $data['data']['id']);
        $this->assertStringContainsString('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    public function test_from_creates_item_with_default_class(): void
    {
        $item = Item::from(['id' => '5', 'type' => 'orders', 'attributes' => []]);
        $this->assertInstanceOf(Item::class, $item);
        $this->assertSame('5', $item->id);
    }
}
