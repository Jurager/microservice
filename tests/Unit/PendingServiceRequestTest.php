<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\Client\PendingServiceRequest;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Client\ServiceResponse;
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
        $this->request->withHeaders(['X-Foo' => 'bar'])->withHeaders(['X-Baz' => 'qux']);

        $this->assertSame(['X-Foo' => 'bar', 'X-Baz' => 'qux'], $this->request->getHeaders());
    }

    public function test_with_query_merges_query_params(): void
    {
        $this->request->withQuery(['page' => 1])->withQuery(['limit' => 10]);

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

    public function test_retries_sets_retries(): void
    {
        $this->request->retries(5);

        $this->assertSame(5, $this->request->getRetries());
    }

    public function test_defaults_are_null_for_timeout_and_retries(): void
    {
        $this->assertNull($this->request->getTimeout());
        $this->assertNull($this->request->getRetries());
    }

    public function test_get_service_returns_service_name(): void
    {
        $this->assertSame('oms', $this->request->getService());
    }

    public function test_send_delegates_to_client(): void
    {
        $mockResponse = Mockery::mock(ServiceResponse::class);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('send')->once()->andReturn($mockResponse);

        $request = new PendingServiceRequest($client, 'oms');
        $result = $request->get('/api/orders')->send();

        $this->assertSame($mockResponse, $result);
    }
}
