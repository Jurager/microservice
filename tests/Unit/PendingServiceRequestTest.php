<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\Client\PendingServiceRequest;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Client\ServiceResponse;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use Mockery;
use PHPUnit\Framework\TestCase;

class PendingServiceRequestTest extends TestCase
{
    private PendingServiceRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Mockery::mock(ServiceClient::class);
        $this->request = new PendingServiceRequest($client, 'oms');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_sets_method_and_path(): void
    {
        $this->request->get('/api/orders');

        $this->assertSame('GET', $this->request->getMethod());
        $this->assertSame('/api/orders', $this->request->getPath());
        $this->assertNull($this->request->getBody());
    }

    public function test_post_sets_method_path_and_body(): void
    {
        $this->request->post('/api/orders', ['item' => 1]);

        $this->assertSame('POST', $this->request->getMethod());
        $this->assertSame('/api/orders', $this->request->getPath());
        $this->assertSame(['item' => 1], $this->request->getBody());
    }

    public function test_put_sets_method_path_and_body(): void
    {
        $this->request->put('/api/orders/1', ['qty' => 5]);

        $this->assertSame('PUT', $this->request->getMethod());
        $this->assertSame(['qty' => 5], $this->request->getBody());
    }

    public function test_patch_sets_method_path_and_body(): void
    {
        $this->request->patch('/api/orders/1', ['qty' => 3]);

        $this->assertSame('PATCH', $this->request->getMethod());
    }

    public function test_delete_sets_method_and_path(): void
    {
        $this->request->delete('/api/orders/1');

        $this->assertSame('DELETE', $this->request->getMethod());
        $this->assertNull($this->request->getBody());
    }

    public function test_with_headers_merges_headers(): void
    {
        $this->request->headers(['X-Foo' => 'bar'])->headers(['X-Baz' => 'qux']);

        $this->assertSame(['X-Foo' => 'bar', 'X-Baz' => 'qux'], $this->request->getHeaders());
    }

    public function test_with_query_merges_query_params(): void
    {
        $this->request->with(['page' => 1])->with(['limit' => 10]);

        $this->assertSame(['page' => 1, 'limit' => 10], $this->request->getQuery());
    }

    public function test_with_body_overrides_body(): void
    {
        $this->request->post('/api/orders', ['a' => 1])->withBody(['b' => 2]);

        $this->assertSame(['b' => 2], $this->request->getBody());
    }

    public function test_fields_merges_body_and_drops_nulls(): void
    {
        $this->request->post('/api/orders')->fields(['a' => 1, 'b' => null])->fields(['c' => 2]);

        $this->assertSame(['a' => 1, 'c' => 2], $this->request->getBody());
    }

    public function test_fields_keeps_falsy_non_null_values(): void
    {
        $this->request->post('/api/orders')->fields(['qty' => 0, 'active' => false, 'name' => '']);

        $this->assertSame(['qty' => 0, 'active' => false, 'name' => ''], $this->request->getBody());
    }

    public function test_timeout_sets_timeout(): void
    {
        $this->request->timeout(10);

        $this->assertSame(10, $this->request->getTimeout());
    }

    public function test_default_timeout_is_null(): void
    {
        $this->assertNull($this->request->getTimeout());
    }

    public function test_get_service_returns_service_name(): void
    {
        $this->assertSame('oms', $this->request->getService());
    }

    public function test_with_multipart_sets_multipart_data(): void
    {
        $data = [
            ['name' => 'import_type', 'contents' => 'products'],
            ['name' => 'import_file', 'contents' => 'stream-placeholder', 'filename' => 'data.json'],
        ];

        $this->request->withMultipart($data);

        $this->assertSame($data, $this->request->getMultipart());
    }

    public function test_skip_if_true_returns_empty_collection_without_sending(): void
    {
        // The mocked client has no shouldReceive('send') expectation — collect() would
        // throw if it tried to reach the network, so a clean result proves it didn't.
        $document = $this->request->get('/api/orders')->skipIf(true)->collect();

        $this->assertTrue($document->isEmpty());
    }

    public function test_skip_if_true_returns_empty_item_without_sending(): void
    {
        $document = $this->request->get('/api/orders/1')->skipIf(true)->item();

        $this->assertSame('', $document->data()->id);
    }

    public function test_skip_if_false_sends_request_normally(): void
    {
        $mockResponse = Mockery::mock(ServiceResponse::class);
        $mockResponse->shouldReceive('failed')->once()->andReturn(false);
        $mockResponse->shouldReceive('json')->once()->andReturn(['data' => []]);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('send')->once()->andReturn($mockResponse);

        $document = (new PendingServiceRequest($client, 'oms'))->get('/api/orders')->skipIf(false)->collect();

        $this->assertTrue($document->isEmpty());
    }

    public function test_multipart_is_null_by_default(): void
    {
        $this->assertNull($this->request->getMultipart());
    }

    public function test_chunk_returns_empty_array_without_a_request(): void
    {
        $result = $this->request->get('/v1/products')->chunk([], fn ($request) => $request);

        $this->assertSame([], $result);
    }

    public function test_chunk_lets_the_caller_shape_the_chunk_request(): void
    {
        $mockResponse = Mockery::mock(ServiceResponse::class);
        $mockResponse->shouldReceive('failed')->andReturn(false);
        $mockResponse->shouldReceive('json')->with('data')->andReturn([['id' => '1'], ['id' => '2']]);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('parallel')->once()->andReturnUsing(function (array $requests) use ($mockResponse) {
            $this->assertCount(1, $requests);
            $this->assertSame(['filter' => ['id' => ['in' => [1, 2]]]], $requests[0]->getBody());

            return [$mockResponse];
        });

        $result = (new PendingServiceRequest($client, 'pim'))
            ->get('/v1/products')
            ->chunk([1, 2], fn ($request, $values) => $request->withBody(['filter' => ['id' => ['in' => $values]]]));

        $this->assertSame([['id' => '1'], ['id' => '2']], $result);
    }

    public function test_chunk_works_for_non_id_values_too(): void
    {
        $mockResponse = Mockery::mock(ServiceResponse::class);
        $mockResponse->shouldReceive('failed')->andReturn(false);
        $mockResponse->shouldReceive('json')->with('data')->andReturn([['id' => '1', 'code' => 'sku-a']]);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('parallel')->once()->andReturnUsing(function (array $requests) use ($mockResponse) {
            $this->assertSame(['filter' => ['code' => ['in' => ['sku-a', 'sku-b']]]], $requests[0]->getBody());

            return [$mockResponse];
        });

        $result = (new PendingServiceRequest($client, 'pim'))
            ->get('/v1/products')
            ->chunk(['sku-a', 'sku-b'], fn ($request, $values) => $request->withBody(['filter' => ['code' => ['in' => $values]]]));

        $this->assertSame([['id' => '1', 'code' => 'sku-a']], $result);
    }

    public function test_chunk_splits_by_chunk_size(): void
    {
        $first = Mockery::mock(ServiceResponse::class);
        $first->shouldReceive('failed')->andReturn(false);
        $first->shouldReceive('json')->with('data')->andReturn([['id' => '1']]);

        $second = Mockery::mock(ServiceResponse::class);
        $second->shouldReceive('failed')->andReturn(false);
        $second->shouldReceive('json')->with('data')->andReturn([['id' => '2']]);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('parallel')->once()->andReturnUsing(function (array $requests) use ($first, $second) {
            $this->assertCount(2, $requests);

            return [$first, $second];
        });

        $result = (new PendingServiceRequest($client, 'pim'))
            ->get('/v1/products')
            ->chunk([1, 2], fn ($request, $values) => $request->withBody(['ids' => $values]), chunkSize: 1);

        $this->assertSame([['id' => '1'], ['id' => '2']], $result);
    }

    public function test_chunk_throws_on_failed_chunk(): void
    {
        $mockResponse = Mockery::mock(ServiceResponse::class);
        $mockResponse->shouldReceive('failed')->andReturn(true);
        $mockResponse->shouldReceive('status')->andReturn(500);
        $mockResponse->shouldReceive('json')->with('errors')->andReturn(null);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('parallel')->once()->andReturn([$mockResponse]);

        $this->expectException(ServiceRequestException::class);

        (new PendingServiceRequest($client, 'pim'))
            ->get('/v1/products')
            ->chunk([1], fn ($request, $values) => $request->withBody(['ids' => $values]));
    }

    public function test_missing_returns_empty_array_without_a_request(): void
    {
        $result = $this->request->missing([], fn ($value) => "/v1/price_types/{$value}");

        $this->assertSame([], $result);
    }

    public function test_missing_goes_through_service_and_returns_404_values(): void
    {
        $ok = Mockery::mock(ServiceResponse::class);
        $ok->shouldReceive('status')->andReturn(200);

        $notFound = Mockery::mock(ServiceResponse::class);
        $notFound->shouldReceive('status')->andReturn(404);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('service')->with('pim')->andReturnUsing(fn () => new PendingServiceRequest($client, 'pim'));
        $client->shouldReceive('parallel')->once()->andReturnUsing(function (array $requests) use ($ok, $notFound) {
            $this->assertSame(['/v1/price_types/1', '/v1/price_types/2'], array_values(array_map(fn ($r) => $r->getPath(), $requests)));

            return [0 => $ok, 1 => $notFound];
        });

        $result = (new PendingServiceRequest($client, 'pim'))->missing([1, 2], fn ($value) => "/v1/price_types/{$value}");

        $this->assertSame([2], $result);
    }

    public function test_missing_works_for_non_id_values_too(): void
    {
        $ok = Mockery::mock(ServiceResponse::class);
        $ok->shouldReceive('status')->andReturn(200);

        $notFound = Mockery::mock(ServiceResponse::class);
        $notFound->shouldReceive('status')->andReturn(404);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('service')->with('pim')->andReturnUsing(fn () => new PendingServiceRequest($client, 'pim'));
        $client->shouldReceive('parallel')->once()->andReturn([0 => $ok, 1 => $notFound]);

        $result = (new PendingServiceRequest($client, 'pim'))->missing(['sku-a', 'sku-b'], fn ($value) => "/v1/products/by-code/{$value}");

        $this->assertSame(['sku-b'], $result);
    }

    public function test_send_delegates_to_client(): void
    {
        $mockResponse = Mockery::mock(ServiceResponse::class);
        $mockResponse->shouldReceive('failed')->once()->andReturn(false);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('send')->once()->andReturn($mockResponse);

        $request = new PendingServiceRequest($client, 'oms');
        $result = $request->get('/api/orders')->send();

        $this->assertSame($mockResponse, $result);
    }

    public function test_send_throws_exception_on_failed_response(): void
    {
        $mockResponse = Mockery::mock(ServiceResponse::class);
        $mockResponse->shouldReceive('failed')->once()->andReturn(true);
        $mockResponse->shouldReceive('status')->once()->andReturn(503);
        $mockResponse->shouldReceive('json')->with('errors')->once()->andReturn(null);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('send')->once()->andReturn($mockResponse);

        $this->expectException(ServiceRequestException::class);

        (new PendingServiceRequest($client, 'oms'))->get('/api/orders')->send();
    }

    public function test_send_exposes_upstream_errors_by_default(): void
    {
        $errors = [['status' => '422', 'detail' => 'Name is required']];

        $mockResponse = Mockery::mock(ServiceResponse::class);
        $mockResponse->shouldReceive('failed')->once()->andReturn(true);
        $mockResponse->shouldReceive('status')->once()->andReturn(422);
        $mockResponse->shouldReceive('json')->with('errors')->once()->andReturn($errors);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('send')->once()->andReturn($mockResponse);

        try {
            (new PendingServiceRequest($client, 'oms'))->get('/api/orders')->send();
            $this->fail('Expected ServiceRequestException');
        } catch (ServiceRequestException $e) {
            $this->assertSame($errors, $e->errors);
        }
    }

    public function test_get_is_memoized_by_default(): void
    {
        $this->request->get('/api/orders');

        $this->assertTrue($this->request->shouldMemoize());
    }

    public function test_post_is_not_memoized_by_default(): void
    {
        $this->request->post('/api/orders', ['a' => 1]);

        $this->assertFalse($this->request->shouldMemoize());
    }

    public function test_without_errors_suppresses_error_details(): void
    {
        $mockResponse = Mockery::mock(ServiceResponse::class);
        $mockResponse->shouldReceive('failed')->once()->andReturn(true);
        $mockResponse->shouldReceive('status')->once()->andReturn(422);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('send')->once()->andReturn($mockResponse);

        try {
            (new PendingServiceRequest($client, 'oms'))->get('/api/orders')->withoutErrors()->send();
            $this->fail('Expected ServiceRequestException');
        } catch (ServiceRequestException $e) {
            $this->assertNull($e->errors);
        }
    }
}
