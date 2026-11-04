<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Jurager\Microservice\JsonApi\CollectionDocument;
use Jurager\Microservice\JsonApi\Item;
use Jurager\Microservice\Tests\TestCase;

class CollectionDocumentTest extends TestCase
{
    public function test_scoped_collections_keep_equal_ids_and_their_relationships_separate(): void
    {
        $body = static fn (string $label): array => [
            'data' => [[
                'type' => 'node', 'id' => '1',
                'attributes' => ['id' => 1, 'peer_id' => 2],
                'meta' => ['custom' => 'kept'],
                'links' => ['self' => '/nodes/1'],
                'relationships' => [
                    'peer' => ['data' => ['type' => 'node', 'id' => '2', 'meta' => ['link' => 'kept']]],
                    'peers' => ['data' => [['type' => 'node', 'id' => '2']]],
                    'empty' => ['data' => []],
                    'missing' => ['data' => null],
                    'unloaded' => ['links' => ['related' => '/nodes/1/unloaded']],
                ],
            ]],
            'included' => [[
                'type' => 'node', 'id' => '2', 'attributes' => ['label' => $label],
                'relationships' => ['parent' => ['data' => ['type' => 'node', 'id' => '1']]],
            ]],
        ];
        $sources = ['pim' => $body('Product'), 'api' => $body('User')];
        $document = CollectionDocument::collect($sources, scoped: true)
            ->withRelations(['peer' => [Item::class, ['parent' => Item::class]]]);

        $this->assertSame(['pim:1', 'api:1'], $document->data()->map(fn (Item $item) => $item->id)->all());
        $this->assertSame(['pim:2', 'api:2'], array_column($document->rawIncluded(), 'id'));

        foreach ($document->data() as $index => $item) {
            $source = $index === 0 ? 'pim' : 'api';
            $this->assertSame($source.':2', $item->relationId('peer'));
            $this->assertSame([$source.':2'], $item->relationIds('peers'));
            $this->assertSame($index === 0 ? 'Product' : 'User', $item->getRelationOne('peer')->attribute('label'));
            $this->assertSame($item, $item->getRelationOne('peer')->getRelationOne('parent'));
            $this->assertSame(['source' => $source, 'id' => '1'], $item->meta()['origin']);
            $this->assertSame('kept', $item->meta()['custom']);
            $this->assertSame(['id' => 1, 'peer_id' => 2], $item->attributes());
            $this->assertSame('/nodes/1', $item->links()['self']);
            $this->assertSame(['link' => 'kept'], $item->relationships()['peer']['data']['meta']);
            $this->assertSame([], $item->relationships()['empty']['data']);
            $this->assertNull($item->relationships()['missing']['data']);
            $this->assertSame('/nodes/1/unloaded', $item->relationships()['unloaded']['links']['related']);
        }

        $this->assertSame('pim:1', CollectionDocument::collect(['pim' => $sources['pim']], scoped: true)->first()->id);
        $this->assertSame('1', $sources['pim']['data'][0]['id']);
        $this->assertSame('2', $sources['api']['included'][0]['id']);

        $document->applyPolicy(static fn (array $resource): array => $resource);
        $this->assertCount(2, $document->rawIncluded());
        $this->assertSame('api:2', $document->data()[1]->relationId('peer'));
    }

    public function test_scoped_ids_escape_components_and_keep_zero_ids(): void
    {
        $document = CollectionDocument::merge([
            'a:b' => ['data' => [['type' => 'node', 'id' => 'c']]],
            'a' => ['data' => [['type' => 'node', 'id' => 'b:c'], ['type' => 'node', 'id' => 0]]],
        ], scoped: true);

        $this->assertSame(['a%3Ab:c', 'a:b%3Ac', 'a:0'], array_column($document['data'], 'id'));
        $this->assertSame(['source' => 'a', 'id' => 'b:c'], $document['data'][1]['meta']['origin']);
    }

    public function test_scoped_collections_require_named_sources(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CollectionDocument::collect([['data' => []]], scoped: true);
    }

    public function test_scoped_collections_reject_empty_source_names(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CollectionDocument::merge(['' => ['data' => []]], scoped: true);
    }

    public function test_combining_bodies_retains_resource_order_and_linkage_without_upstream_pagination(): void
    {
        $first = [
            'data' => [['type' => 'node', 'id' => '1', 'relationships' => ['peer' => ['data' => ['type' => 'node', 'id' => '2']]]]],
            'included' => [['type' => 'node', 'id' => '2', 'attributes' => ['name' => 'Peer']]],
            'meta' => ['total' => 1],
            'links' => ['next' => '/first?page=2'],
        ];
        $second = [
            'data' => [['type' => 'node', 'id' => '3']],
            'included' => [['type' => 'node', 'id' => '2', 'attributes' => ['extra' => true]]],
            'meta' => ['total' => 10],
            'links' => ['next' => '/second?page=2'],
        ];

        $bodies = (static function () use ($first, $second) {
            yield $first;
            yield $second;
        })();
        $document = CollectionDocument::collect($bodies, Item::class);

        $this->assertSame(['1', '3'], $document->data()->pluck('id')->all());
        $this->assertInstanceOf(Item::class, $document->first());
        $this->assertSame(['name' => 'Peer', 'extra' => true], $document->first()->getRelationOne('peer')->attributes());
        $this->assertCount(1, $document->rawIncluded());
        $this->assertSame([], $document->meta());
        $this->assertSame([], $document->links());
        $this->assertSame($first['data'], CollectionDocument::merge([$first])['data']);
    }

    public function test_combining_empty_bodies_produces_an_empty_collection(): void
    {
        $this->assertSame(['data' => [], 'included' => []], CollectionDocument::merge([]));
        $this->assertTrue(CollectionDocument::collect([[], ['data' => []]])->isEmpty());
    }

    public function test_primary_resources_resolve_each_other_without_included_copies(): void
    {
        $doc = new CollectionDocument(['data' => [
            ['type' => 'node', 'id' => '1', 'relationships' => ['peer' => ['data' => ['type' => 'node', 'id' => '2']]]],
            ['type' => 'node', 'id' => '2', 'relationships' => ['peer' => ['data' => ['type' => 'node', 'id' => '1']]]],
        ]]);

        $this->assertSame($doc->data()[1], $doc->data()[0]->getRelationOne('peer'));
        $this->assertSame($doc->data()[0], $doc->data()[1]->getRelationOne('peer'));
        $doc->applyPolicy(static fn (array $resource): array => $resource);
        $this->assertSame('2', $doc->data()[0]->relationshipData('peer')['id']);
        $this->assertSame([], $doc->rawIncluded());
    }

    public function test_primary_copies_are_removed_without_discarding_links_on_other_included_items(): void
    {
        $primary = ['type' => 'node', 'id' => '1', 'relationships' => ['peer' => ['data' => ['type' => 'node', 'id' => '2']]]];
        $doc = new CollectionDocument([
            'data' => [$primary],
            'included' => [$primary, ['type' => 'node', 'id' => '2', 'links' => ['self' => '/nodes/2']]],
        ]);

        $this->assertCount(1, $doc->rawIncluded());
        $this->assertSame('/nodes/2', $doc->first()->getRelationOne('peer')->links()['self']);
    }

    private array $body = [
        'data' => [
            ['id' => '1', 'type' => 'orders', 'attributes' => ['status' => 'pending']],
            ['id' => '2', 'type' => 'orders', 'attributes' => ['status' => 'shipped']],
        ],
        'meta' => ['total' => 2],
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

    public function test_empty_builds_document_with_no_items(): void
    {
        $doc = CollectionDocument::empty();

        $this->assertTrue($doc->isEmpty());
        $this->assertCount(0, $doc->data());
        $this->assertNull($doc->first());
    }

    public function test_empty_uses_given_item_class(): void
    {
        $doc = CollectionDocument::empty(Item::class);

        $this->assertSame(0, $doc->count());
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
        $doc->each(function (Item $item) use (&$ids) {
            $ids[] = $item->id;
        });
        $this->assertSame(['1', '2'], $ids);
    }

    public function test_map_transforms_items(): void
    {
        $doc = new CollectionDocument($this->body);
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
        $doc = new CollectionDocument($this->body);
        $payload = json_decode(
            $doc->through(fn (Item $item) => ['custom_id' => $item->id])->toResponse()->getContent(),
            true
        );
        $this->assertSame('1', $payload['data'][0]['custom_id']);
    }

    public function test_links_point_at_the_request_the_document_answers(): void
    {
        $doc = new CollectionDocument([
            'data' => [['id' => '1', 'type' => 'products', 'links' => ['self' => 'http://pim.internal/v1/products/1']]],
            'links' => ['next' => 'http://pim.internal/v1/products?page=2'],
        ]);

        $this->assertSame('http://pim.internal/v1/products/1', $doc->first()->links()['self'], 'An item keeps its links as received.');

        $payload = $doc->toResponse(Request::create('https://shop.example/catalog'))->getData(true);

        $this->assertSame('https://shop.example/v1/products/1', $payload['data'][0]['links']['self']);
        $this->assertSame('https://shop.example/catalog?page=2', $payload['links']['next']);
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
            'data' => [],
            'included' => [['type' => 'customers', 'id' => '1', 'attributes' => ['name' => 'Bob']]],
        ];
        $this->assertCount(1, (new CollectionDocument($body))->rawIncluded());
    }

    public function test_filter_included_reduces_set(): void
    {
        $body = [
            'data' => [],
            'included' => [
                ['type' => 'customers', 'id' => '1', 'attributes' => []],
                ['type' => 'products',  'id' => '2', 'attributes' => []],
            ],
        ];

        $doc = new CollectionDocument($body);
        $doc->filterIncluded(fn ($r) => $r['type'] === 'customers');
        $this->assertCount(1, $doc->rawIncluded());
    }

    /**
     * Remove included resources orphaned by a policy at any depth.
     */
    public function test_apply_policy_prunes_resources_orphaned_by_a_removed_parent(): void
    {
        $body = [
            'data' => [
                [
                    'id' => '1', 'type' => 'products', 'attributes' => [],
                    'relationships' => ['attributes' => ['data' => [
                        ['type' => 'entityAttribute', 'id' => 'a'],
                        ['type' => 'entityAttribute', 'id' => 'b'],
                    ]]],
                ],
            ],
            'included' => [
                [
                    'id' => 'a', 'type' => 'entityAttribute', 'attributes' => ['visible' => true],
                    'relationships' => ['translations' => ['data' => [['type' => 'translation', 'id' => 'a1']]]],
                ],
                [
                    'id' => 'b', 'type' => 'entityAttribute', 'attributes' => ['visible' => false],
                    'relationships' => ['translations' => ['data' => [['type' => 'translation', 'id' => 'b1']]]],
                ],
                ['id' => 'a1', 'type' => 'translation', 'attributes' => ['label' => 'kept']],
                ['id' => 'b1', 'type' => 'translation', 'attributes' => ['label' => 'orphaned']],
            ],
        ];

        $doc = new CollectionDocument($body);
        $doc->applyPolicy(fn ($r) => ($r['type'] === 'entityAttribute' && ! $r['attributes']['visible']) ? null : $r);

        $ids = array_column($doc->rawIncluded(), 'id');
        sort($ids);
        $this->assertSame(['a', 'a1'], $ids);
    }

    public function test_to_response_includes_included_when_present(): void
    {
        $body = [
            'data' => [['id' => '1', 'type' => 'orders', 'attributes' => []]],
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
