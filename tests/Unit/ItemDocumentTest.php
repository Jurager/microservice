<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\JsonApi\Item;
use Jurager\Microservice\JsonApi\ItemDocument;
use PHPUnit\Framework\TestCase;

class ItemDocumentTest extends TestCase
{
    private array $body = [
        'data' => [
            'id' => '42',
            'type' => 'orders',
            'attributes' => ['status' => 'pending', 'total' => 99.5],
        ],
        'meta' => ['server_time' => '2025-01-01'],
        'links' => ['self' => '/orders/42'],
    ];

    public function test_data_returns_item(): void
    {
        $doc = new ItemDocument($this->body);
        $this->assertInstanceOf(Item::class, $doc->data());
        $this->assertSame('42', $doc->data()->id);
    }

    public function test_meta_returns_meta_array(): void
    {
        $this->assertSame(['server_time' => '2025-01-01'], (new ItemDocument($this->body))->meta());
    }

    public function test_links_returns_links_array(): void
    {
        $this->assertSame(['self' => '/orders/42'], (new ItemDocument($this->body))->links());
    }

    public function test_empty_body_creates_empty_item(): void
    {
        $doc = new ItemDocument([]);
        $this->assertSame('', $doc->data()->id);
        $this->assertSame([], $doc->meta());
        $this->assertSame([], $doc->links());
    }

    public function test_with_relations_attaches_included(): void
    {
        $body = [
            'data' => [
                'id' => '10', 'type' => 'orders', 'attributes' => [],
                'relationships' => ['customer' => ['data' => ['type' => 'customers', 'id' => '7']]],
            ],
            'included' => [
                ['type' => 'customers', 'id' => '7', 'attributes' => ['name' => 'Alice']],
            ],
        ];

        $doc = (new ItemDocument($body))->withRelations(['customer' => Item::class]);

        $this->assertTrue($doc->data()->hasResolved('customer'));
        $this->assertSame('7', $doc->data()->getRelationOne('customer')->id);
    }

    public function test_to_response_without_transform(): void
    {
        $response = (new ItemDocument($this->body))->toResponse();
        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode($response->getContent(), true);
        $this->assertSame('42', $payload['data']['id']);
    }

    public function test_to_response_with_transform(): void
    {
        $doc = new ItemDocument($this->body);
        $payload = json_decode(
            $doc->toResponse(fn (Item $item) => ['custom_id' => $item->id])->getContent(),
            true
        );
        $this->assertSame('42', $payload['data']['custom_id']);
    }

    public function test_to_response_includes_meta_and_links(): void
    {
        $payload = json_decode((new ItemDocument($this->body))->toResponse()->getContent(), true);
        $this->assertSame(['server_time' => '2025-01-01'], $payload['meta']);
        $this->assertSame(['self' => '/orders/42'], $payload['links']);
    }

    public function test_to_response_includes_included_when_present(): void
    {
        $body = [
            'data' => ['id' => '1', 'type' => 'orders', 'attributes' => []],
            'included' => [['type' => 'customers', 'id' => '1', 'attributes' => ['name' => 'Bob']]],
        ];

        $payload = json_decode((new ItemDocument($body))->toResponse()->getContent(), true);
        $this->assertArrayHasKey('included', $payload);
        $this->assertCount(1, $payload['included']);
    }

    public function test_to_response_has_json_api_content_type(): void
    {
        $response = (new ItemDocument($this->body))->toResponse();
        $this->assertStringContainsString('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    public function test_raw_included_returns_included_array(): void
    {
        $body = [
            'data' => ['id' => '1', 'type' => 'orders', 'attributes' => []],
            'included' => [['type' => 'customers', 'id' => '1', 'attributes' => ['name' => 'Bob']]],
        ];
        $this->assertCount(1, (new ItemDocument($body))->rawIncluded());
    }

    public function test_filter_included_reduces_set(): void
    {
        $body = [
            'data' => ['id' => '1', 'type' => 'orders', 'attributes' => []],
            'included' => [
                ['type' => 'customers', 'id' => '1', 'attributes' => []],
                ['type' => 'products',  'id' => '2', 'attributes' => []],
            ],
        ];

        $doc = new ItemDocument($body);
        $doc->filterIncluded(fn ($r) => $r['type'] === 'customers');
        $this->assertCount(1, $doc->rawIncluded());
    }

    /**
     * Regression guard: a resource orphaned two levels deep (its only parent removed by the
     * policy, not the root item itself) must not linger in `included`.
     */
    public function test_apply_policy_prunes_resources_orphaned_by_a_removed_parent(): void
    {
        $body = [
            'data' => [
                'id' => '1', 'type' => 'products', 'attributes' => [],
                'relationships' => ['attributes' => ['data' => [
                    ['type' => 'entityAttribute', 'id' => 'a'],
                    ['type' => 'entityAttribute', 'id' => 'b'],
                ]]],
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

        $doc = new ItemDocument($body);
        $doc->applyPolicy(fn ($r) => ($r['type'] === 'entityAttribute' && ! $r['attributes']['visible']) ? null : $r);

        $ids = array_column($doc->rawIncluded(), 'id');
        sort($ids);
        $this->assertSame(['a', 'a1'], $ids);
    }

    public function test_auto_attach_runs_on_construction(): void
    {
        $body = [
            'data' => [
                'id' => '1', 'type' => 'orders', 'attributes' => [],
                'relationships' => ['customer' => ['data' => ['type' => 'customers', 'id' => '7']]],
            ],
            'included' => [
                ['type' => 'customers', 'id' => '7', 'attributes' => ['name' => 'Alice']],
            ],
        ];

        $doc = new ItemDocument($body);

        $this->assertTrue($doc->data()->hasResolved('customer'));
    }
}
