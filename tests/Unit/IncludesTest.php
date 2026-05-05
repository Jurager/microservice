<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\JsonApi\Includes;
use Jurager\Microservice\JsonApi\Item;
use PHPUnit\Framework\TestCase;

class IncludesTest extends TestCase
{
    private array $included = [
        ['type' => 'customers', 'id' => '7', 'attributes' => ['name' => 'Alice'], 'links' => ['self' => '/customers/7']],
        ['type' => 'products',  'id' => '3', 'attributes' => ['name' => 'Widget']],
    ];

    public function test_is_empty_with_no_resources(): void
    {
        $this->assertTrue((new Includes())->isEmpty());
    }

    public function test_is_not_empty_when_resources_present(): void
    {
        $this->assertFalse((new Includes($this->included))->isEmpty());
    }

    public function test_find_returns_resource_by_type_and_id(): void
    {
        $includes = new Includes($this->included);
        $resource = $includes->find('customers', '7');
        $this->assertNotNull($resource);
        $this->assertSame('7', $resource['id']);
    }

    public function test_find_returns_null_for_unknown_type(): void
    {
        $this->assertNull((new Includes($this->included))->find('unknown', '7'));
    }

    public function test_find_returns_null_for_unknown_id(): void
    {
        $this->assertNull((new Includes($this->included))->find('customers', '999'));
    }

    public function test_links_are_stripped_from_raw(): void
    {
        $includes = new Includes($this->included);
        $this->assertArrayNotHasKey('links', $includes->raw()[0]);
    }

    public function test_raw_returns_all_resources(): void
    {
        $this->assertCount(2, (new Includes($this->included))->raw());
    }

    public function test_filter_keeps_matching_resources(): void
    {
        $includes = new Includes($this->included);
        $includes->filter(fn ($r) => $r['type'] === 'customers');

        $this->assertCount(1, $includes->raw());
        $this->assertNotNull($includes->find('customers', '7'));
        $this->assertNull($includes->find('products', '3'));
    }

    public function test_resources_without_type_excluded_from_index(): void
    {
        $includes = new Includes([['id' => '5', 'attributes' => []]]);
        $this->assertTrue($includes->isEmpty());
    }

    public function test_resources_with_empty_id_excluded_from_index(): void
    {
        $includes = new Includes([['type' => 'orders', 'id' => '', 'attributes' => []]]);
        $this->assertTrue($includes->isEmpty());
    }

    public function test_attach_relations_resolves_to_many(): void
    {
        $includes = new Includes([
            ['type' => 'order-lines', 'id' => '1', 'attributes' => ['qty' => 2]],
            ['type' => 'order-lines', 'id' => '2', 'attributes' => ['qty' => 5]],
        ]);

        $item = new Item([
            'id' => '10', 'type' => 'orders', 'attributes' => [],
            'relationships' => [
                'lines' => ['data' => [
                    ['type' => 'order-lines', 'id' => '1'],
                    ['type' => 'order-lines', 'id' => '2'],
                ]],
            ],
        ]);

        $includes->attachRelations($item, ['lines' => Item::class]);

        $this->assertTrue($item->hasResolved('lines'));
        $this->assertCount(2, $item->getRelation('lines'));
    }

    public function test_attach_relations_resolves_to_one(): void
    {
        $includes = new Includes([
            ['type' => 'customers', 'id' => '7', 'attributes' => ['name' => 'Alice']],
        ]);

        $item = new Item([
            'id' => '10', 'type' => 'orders', 'attributes' => [],
            'relationships' => ['customer' => ['data' => ['type' => 'customers', 'id' => '7']]],
        ]);

        $includes->attachRelations($item, ['customer' => Item::class]);

        $this->assertTrue($item->hasResolved('customer'));
        $this->assertSame('7', $item->getRelationOne('customer')->id);
    }

    public function test_attach_relations_skips_missing_resources(): void
    {
        $includes = new Includes([]);

        $item = new Item([
            'id' => '10', 'type' => 'orders', 'attributes' => [],
            'relationships' => ['customer' => ['data' => ['type' => 'customers', 'id' => '99']]],
        ]);

        $includes->attachRelations($item, ['customer' => Item::class]);

        $this->assertTrue($item->hasResolved('customer'));
        $this->assertNull($item->getRelationOne('customer'));
    }

    public function test_attach_relations_with_submap_recurses(): void
    {
        $includes = new Includes([
            [
                'type' => 'customers', 'id' => '7', 'attributes' => ['name' => 'Alice'],
                'relationships' => ['address' => ['data' => ['type' => 'addresses', 'id' => '3']]],
            ],
            ['type' => 'addresses', 'id' => '3', 'attributes' => ['city' => 'Paris']],
        ]);

        $item = new Item([
            'id' => '10', 'type' => 'orders', 'attributes' => [],
            'relationships' => ['customer' => ['data' => ['type' => 'customers', 'id' => '7']]],
        ]);

        $includes->attachRelations($item, [
            'customer' => [Item::class, ['address' => Item::class]],
        ]);

        $customer = $item->getRelationOne('customer');
        $this->assertNotNull($customer);
        $this->assertTrue($customer->hasResolved('address'));
        $this->assertSame('3', $customer->getRelationOne('address')->id);
    }

    public function test_auto_attach_resolves_all_relations(): void
    {
        $includes = new Includes([
            ['type' => 'customers', 'id' => '7', 'attributes' => ['name' => 'Alice']],
        ]);

        $item = new Item([
            'id' => '10', 'type' => 'orders', 'attributes' => [],
            'relationships' => ['customer' => ['data' => ['type' => 'customers', 'id' => '7']]],
        ]);

        $includes->autoAttach($item);

        $this->assertTrue($item->hasResolved('customer'));
    }

    public function test_auto_attach_skips_already_resolved(): void
    {
        $includes  = new Includes([
            ['type' => 'customers', 'id' => '7', 'attributes' => ['name' => 'Alice']],
        ]);
        $existing  = new Item(['id' => '99', 'type' => 'customers', 'attributes' => []]);

        $item = new Item([
            'id' => '10', 'type' => 'orders', 'attributes' => [],
            'relationships' => ['customer' => ['data' => ['type' => 'customers', 'id' => '7']]],
        ]);

        $item->setResolved('customer', [$existing]);
        $includes->autoAttach($item);

        $this->assertSame('99', $item->getRelationOne('customer')->id);
    }

    public function test_auto_attach_does_nothing_when_empty(): void
    {
        $includes = new Includes([]);
        $item     = new Item([
            'id' => '1', 'type' => 'orders', 'attributes' => [],
            'relationships' => ['customer' => ['data' => ['type' => 'customers', 'id' => '7']]],
        ]);

        $includes->autoAttach($item);

        $this->assertFalse($item->hasResolved('customer'));
    }
}
