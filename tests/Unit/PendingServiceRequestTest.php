<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Jurager\Microservice\Client\PendingServiceRequest;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Client\ServiceResponse;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use LogicException;
use Mockery;
use PHPUnit\Framework\TestCase;

class PendingServiceRequestTest extends TestCase
{
    private PendingServiceRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = new PendingServiceRequest(Mockery::mock(ServiceClient::class), 'oms');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private static function response(int $status = 200, array|string $body = []): ServiceResponse
    {
        return new ServiceResponse(new Response($status, [], is_array($body) ? json_encode($body) : $body));
    }

    /** A client answering send() and parallel() with the given responses, in order. */
    private static function client(ServiceResponse ...$responses): ServiceClient
    {
        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('send')->andReturnUsing(static function () use (&$responses): ServiceResponse {
            return array_shift($responses);
        });
        $client->shouldReceive('parallel')->andReturnUsing(static function (array $requests) use (&$responses): array {
            return array_map(static function () use (&$responses): ServiceResponse {
                return array_shift($responses);
            }, $requests);
        });

        return $client;
    }

    public function test_verbs_set_method_path_and_json_body(): void
    {
        $this->request->post('/api/orders', ['item' => 1]);

        $this->assertSame('POST', $this->request->getMethod());
        $this->assertSame('/api/orders', $this->request->getPath());
        $this->assertSame(['item' => 1], $this->request->getJson());
        $this->assertSame('{"item":1}', $this->request->getBody());

        $this->request->delete('/api/orders/1');

        $this->assertSame('DELETE', $this->request->getMethod());
        $this->assertNull($this->request->getBody());
    }

    public function test_a_write_gets_a_request_id_and_a_read_drops_it(): void
    {
        $this->request->post('/api/orders');
        $this->assertArrayHasKey('X-Request-Id', $this->request->getHeaders());

        $this->request->get('/api/orders');
        $this->assertArrayNotHasKey('X-Request-Id', $this->request->getHeaders());
    }

    public function test_with_headers_merges_headers(): void
    {
        $this->request->withHeaders(['X-Foo' => 'bar'])->withHeaders(['X-Baz' => 'qux']);

        $this->assertSame(['X-Foo' => 'bar', 'X-Baz' => 'qux'], $this->request->getHeaders());
    }

    public function test_with_query_parameters_merges_and_drops_nulls(): void
    {
        $this->request->withQueryParameters(['page' => 1, 'q' => null])->withQueryParameters(['limit' => 10]);

        $this->assertSame(['page' => 1, 'limit' => 10], $this->request->getQuery());
    }

    public function test_merge_query_parameters_joins_comma_lists_and_merges_arrays(): void
    {
        $this->request
            ->withQueryParameters(['sort' => 'name', 'filter' => ['a' => 1]])
            ->mergeQueryParameters(['sort' => 'price,name', 'filter' => ['b' => 2]]);

        $this->assertSame(['sort' => 'name,price', 'filter' => ['a' => 1, 'b' => 2]], $this->request->getQuery());
    }

    public function test_map_query_changes_the_query_that_is_sent(): void
    {
        $this->request->get('/v1/products')
            ->withQueryParameters(['page' => 1])
            ->mapQuery(static fn (array $query): array => [...$query, 'filter' => ['search' => 'lamp']]);

        $this->assertSame('/v1/products?page=1&filter%5Bsearch%5D=lamp', $this->request->target());
    }

    public function test_with_json_replaces_and_merge_json_merges_dropping_nulls(): void
    {
        $this->request->post('/api/orders', ['a' => 1])->withJson(['b' => 2]);
        $this->assertSame(['b' => 2], $this->request->getJson());

        $this->request->mergeJson(['c' => null, 'qty' => 0, 'active' => false, 'name' => '']);
        $this->assertSame(['b' => 2, 'qty' => 0, 'active' => false, 'name' => ''], $this->request->getJson());
    }

    public function test_with_body_sends_content_as_given(): void
    {
        $this->request->post('/api/orders', ['a' => 1])->withBody('<order/>', 'application/xml');

        $this->assertNull($this->request->getJson());
        $this->assertSame('<order/>', $this->request->getBody());
        $this->assertSame('application/xml', $this->request->getContentType());
    }

    public function test_timeout_and_multipart(): void
    {
        $this->assertNull($this->request->getTimeout());
        $this->assertNull($this->request->getMultipart());

        $parts = [['name' => 'import_type', 'contents' => 'products']];
        $this->request->timeout(10)->withMultipart($parts);

        $this->assertSame(10, $this->request->getTimeout());
        $this->assertSame($parts, $this->request->getMultipart());
    }

    public function test_only_safe_requests_and_idempotent_writes_are_repeatable(): void
    {
        $this->assertTrue($this->request->get('/a')->isRepeatable());
        $this->assertFalse($this->request->post('/a')->isRepeatable());
        $this->assertTrue($this->request->post('/a')->idempotent()->isRepeatable());
    }

    public function test_get_is_memoized_and_post_is_not(): void
    {
        $this->assertTrue($this->request->get('/api/orders')->shouldMemoize());
        $this->assertFalse($this->request->post('/api/orders', ['a' => 1])->shouldMemoize());
    }

    public function test_skip_if_true_returns_empty_documents_without_sending(): void
    {
        // The mocked client expects no send() — a clean result proves nothing was sent.
        $this->assertTrue($this->request->get('/api/orders')->skipIf(true)->collect()->isEmpty());
        $this->assertSame('', $this->request->get('/api/orders/1')->skipIf(true)->item()->data()->id);
    }

    public function test_a_skipped_request_cannot_be_sent(): void
    {
        $this->expectException(LogicException::class);

        $this->request->get('/api/orders')->skipIf(true)->send();
    }

    public function test_skip_if_false_sends_the_request(): void
    {
        $document = (new PendingServiceRequest(self::client(self::response(body: ['data' => []])), 'oms'))
            ->get('/api/orders')->skipIf(false)->collect();

        $this->assertTrue($document->isEmpty());
    }

    public function test_map_body_reshapes_the_document_before_it_is_built(): void
    {
        $document = (new PendingServiceRequest(self::client(self::response(body: ['data' => [['id' => '1', 'type' => 'products']]])), 'pim'))
            ->get('/v1/products')
            ->mapBody(static fn (array $body): array => [...$body, 'meta' => ['total' => 1]])
            ->collect();

        $this->assertSame(1, $document->total());
    }

    public function test_send_returns_a_successful_response(): void
    {
        $response = self::response();

        $this->assertSame($response, (new PendingServiceRequest(self::client($response), 'oms'))->get('/api/orders')->send());
    }

    public function test_send_throws_with_the_upstream_errors_on_failure(): void
    {
        $errors = [['status' => '422', 'detail' => 'Name is required']];

        try {
            (new PendingServiceRequest(self::client(self::response(422, ['errors' => $errors])), 'oms'))->get('/api/orders')->send();
            $this->fail('Expected ServiceRequestException');
        } catch (ServiceRequestException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('oms', $e->service);
            $this->assertSame($errors, $e->errors);
        }
    }

    public function test_without_errors_leaves_the_upstream_errors_out(): void
    {
        try {
            (new PendingServiceRequest(self::client(self::response(422, ['errors' => [['detail' => 'x']]])), 'oms'))->get('/api/orders')->withoutErrors()->send();
            $this->fail('Expected ServiceRequestException');
        } catch (ServiceRequestException $e) {
            $this->assertNull($e->errors);
        }
    }

    public function test_without_throwing_returns_a_failed_response(): void
    {
        $response = (new PendingServiceRequest(self::client(self::response(404)), 'pim'))->get('/v1/currencies/1')->withoutThrowing()->send();

        $this->assertSame(404, $response->status());
    }

    public function test_chunk_returns_empty_array_without_a_request(): void
    {
        $this->assertSame([], $this->request->get('/v1/products')->chunk([], fn ($request) => $request));
    }

    public function test_chunk_lets_the_caller_shape_each_chunk_and_concatenates_the_data(): void
    {
        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('parallel')->once()->andReturnUsing(function (array $requests, int $concurrency) {
            $this->assertSame(5, $concurrency);
            $this->assertSame([['ids' => [1]], ['ids' => [2]]], array_map(fn (PendingServiceRequest $r) => $r->getJson(), $requests));

            return [self::response(body: ['data' => [['id' => '1']]]), self::response(body: ['data' => [['id' => '2']]])];
        });

        $result = (new PendingServiceRequest($client, 'pim'))
            ->get('/v1/products')
            ->chunk([1, 2], fn ($request, $values) => $request->withJson(['ids' => $values]), chunkSize: 1);

        $this->assertSame([['id' => '1'], ['id' => '2']], $result);
    }

    public function test_chunk_throws_on_a_failed_chunk(): void
    {
        $this->expectException(ServiceRequestException::class);

        (new PendingServiceRequest(self::client(self::response(500)), 'pim'))
            ->get('/v1/products')
            ->chunk([1], fn ($request, $values) => $request->withJson(['ids' => $values]));
    }

    public function test_chunks_of_a_write_carry_their_own_request_ids(): void
    {
        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('parallel')->once()->andReturnUsing(function (array $requests) {
            $ids = array_map(fn (PendingServiceRequest $r) => $r->getHeaders()['X-Request-Id'], $requests);

            $this->assertCount(2, array_unique($ids));

            return [];
        });

        (new PendingServiceRequest($client, 'pim'))
            ->post('/v1/products/search')
            ->chunk([1, 2], fn ($request, $values) => $request->withJson(['ids' => $values]), chunkSize: 1);
    }

    public function test_missing_returns_empty_array_without_a_request(): void
    {
        $this->assertSame([], $this->request->missing([], fn ($value) => "/v1/price_types/{$value}"));
    }

    public function test_missing_returns_the_values_answered_with_404_keeping_the_request_settings(): void
    {
        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('parallel')->once()->andReturnUsing(function (array $requests) {
            $this->assertSame(['/v1/price_types/1', '/v1/price_types/sku-b'], array_values(array_map(fn ($r) => $r->getPath(), $requests)));

            foreach ($requests as $request) {
                $this->assertSame('GET', $request->getMethod());
                $this->assertSame('de', $request->getHeaders()['Accept-Language']);
                $this->assertSame(3, $request->getTimeout());
            }

            return [0 => self::response(), 1 => self::response(404)];
        });

        $result = (new PendingServiceRequest($client, 'pim'))
            ->withHeaders(['Accept-Language' => 'de'])
            ->timeout(3)
            ->missing([1, 'sku-b'], fn ($value) => "/v1/price_types/{$value}");

        $this->assertSame(['sku-b'], $result);
    }

    public function test_missing_throws_when_existence_cannot_be_determined(): void
    {
        $this->expectException(ServiceRequestException::class);

        (new PendingServiceRequest(self::client(self::response(404), self::response(500)), 'pim'))
            ->missing([1, 2], fn ($value) => "/v1/price_types/{$value}");
    }
}
