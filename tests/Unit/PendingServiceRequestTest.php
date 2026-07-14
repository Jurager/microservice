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

    public function test_multipart_is_null_by_default(): void
    {
        $this->assertNull($this->request->getMultipart());
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
