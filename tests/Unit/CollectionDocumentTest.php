<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\JsonApi\CollectionDocument;
use Jurager\Microservice\JsonApi\Item;
use Jurager\Microservice\Tests\TestCase;

class CollectionDocumentTest extends TestCase
{
    private array $body = [
        'data' => [
            ['id' => '1', 'type' => 'orders', 'attributes' => ['status' => 'pending']],
            ['id' => '2', 'type' => 'orders', 'attributes' => ['status' => 'shipped']],
        ],
        'meta'  => ['total' => 2],
        'links' => ['next' => null, 'prev' => null],
    ];

    public function test_data_returns_collection_of_items(): void
    {
        $doc = new CollectionDocument($this->body);
        $this->assertCount(2, $doc->data());
        $this->assertInstanceOf(Item::class, $doc->data()->first());
    }

    public function test_first_returns_first_item(): void
    {
        $this->assertSame('1', (new CollectionDocument($this->body))->first()->id);
    }

    public function test_first_returns_null_for_empty_data(): void
    {
        $this->assertNull((new CollectionDocument(['data' => []]))->first());
    }

    public function test_meta_returns_meta_array(): void
    {
        $this->assertSame(['total' => 2], (new CollectionDocument($this->body))->meta());
    }

    public function test_total_returns_meta_total_as_int(): void
    {
        $this->assertSame(2, (new CollectionDocument($this->body))->total());
    }

    public function test_total_returns_null_when_missing(): void
    {
        $this->assertNull((new CollectionDocument(['data' => []]))->total());
    }

    public function test_is_empty_and_count(): void
    {
        $empty = new CollectionDocument(['data' => []]);
        $this->assertTrue($empty->isEmpty());
        $this->assertSame(0, $empty->count());

        $doc = new CollectionDocument($this->body);
        $this->assertFalse($doc->isEmpty());
        $this->assertSame(2, $doc->count());
    }

    public function test_each_iterates_all_items(): void
    {
        $doc = new CollectionDocument($this->body);
        $ids = [];
        $doc->each(function (Item $item) use (&$ids) { $ids[] = $item->id; });
        $this->assertSame(['1', '2'], $ids);
    }

    public function test_map_transforms_items(): void
    {
        $doc    = new CollectionDocument($this->body);
        $result = $doc->map(fn (Item $item) => $item->id)->values()->all();
        $this->assertSame(['1', '2'], $result);
    }

    public function test_to_response_without_transform(): void
    {
        $response = (new CollectionDocument($this->body))->toResponse();
        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode($response->getContent(), true);
        $this->assertCount(2, $payload['data']);
        $this->assertSame('1', $payload['data'][0]['id']);
    }

    public function test_to_response_with_transform(): void
    {
        $doc     = new CollectionDocument($this->body);
        $payload = json_decode(
            $doc->toResponse(fn (Item $item) => ['custom_id' => $item->id])->getContent(),
            true
        );
        $this->assertSame('1', $payload['data'][0]['custom_id']);
    }

    public function test_to_response_includes_meta(): void
    {
        $payload = json_decode((new CollectionDocument($this->body))->toResponse()->getContent(), true);
        $this->assertSame(['total' => 2], $payload['meta']);
    }

    public function test_to_response_has_json_api_content_type(): void
    {
        $response = (new CollectionDocument($this->body))->toResponse();
        $this->assertStringContainsString('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    public function test_with_relations_attaches_included_to_each_item(): void
    {
        $body = [
            'data' => [
                [
                    'id' => '1', 'type' => 'orders', 'attributes' => [],
                    'relationships' => ['customer' => ['data' => ['type' => 'customers', 'id' => '7']]],
                ],
            ],
            'included' => [
                ['type' => 'customers', 'id' => '7', 'attributes' => ['name' => 'Alice']],
            ],
        ];

        $doc = (new CollectionDocument($body))->withRelations(['customer' => Item::class]);

        $this->assertTrue($doc->first()->hasResolved('customer'));
        $this->assertSame('7', $doc->first()->getRelationOne('customer')->id);
    }

    public function test_raw_included_returns_included_data(): void
    {
        $body = [
            'data'     => [],
            'included' => [['type' => 'customers', 'id' => '1', 'attributes' => ['name' => 'Bob']]],
        ];
        $this->assertCount(1, (new CollectionDocument($body))->rawIncluded());
    }

    public function test_filter_included_reduces_set(): void
    {
        $body = [
            'data'     => [],
            'included' => [
                ['type' => 'customers', 'id' => '1', 'attributes' => []],
                ['type' => 'products',  'id' => '2', 'attributes' => []],
            ],
        ];

        $doc = new CollectionDocument($body);
        $doc->filterIncluded(fn ($r) => $r['type'] === 'customers');
        $this->assertCount(1, $doc->rawIncluded());
    }

    public function test_to_response_includes_included_when_present(): void
    {
        $body = [
            'data'     => [['id' => '1', 'type' => 'orders', 'attributes' => []]],
            'included' => [['type' => 'customers', 'id' => '1', 'attributes' => ['name' => 'Bob']]],
        ];

        $payload = json_decode((new CollectionDocument($body))->toResponse()->getContent(), true);
        $this->assertArrayHasKey('included', $payload);
        $this->assertCount(1, $payload['included']);
    }

    public function test_empty_body_is_handled_gracefully(): void
    {
        $doc = new CollectionDocument([]);
        $this->assertTrue($doc->isEmpty());
        $this->assertSame([], $doc->meta());
        $this->assertSame([], $doc->links());
    }
}
