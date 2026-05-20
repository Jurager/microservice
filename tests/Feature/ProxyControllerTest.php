<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Jurager\Microservice\Client\PendingServiceRequest;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Client\ServiceResponse;
use Jurager\Microservice\Http\Controllers\ProxyController;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class ProxyControllerTest extends TestCase
{
    private ?object $capturedRequest = null;

    private ?ServiceResponse $mockResponse = null;

    private ?array $capturedMultipart = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockResponse = new ServiceResponse(new Response(200, [
            'Content-Type' => 'application/json',
        ], '{"data":"proxied"}'));

        $test = $this;

        $mock = Mockery::mock(ServiceClient::class);

        $mock->shouldReceive('service')
            ->andReturnUsing(function ($name) use ($test) {
                $pending = Mockery::mock(PendingServiceRequest::class);

                $pending->shouldReceive('withMethod')->andReturnUsing(
                    function ($method, $path) use ($test, $pending) {
                        $test->capturedRequest = (object) [
                            'method' => $method,
                            'path' => $path,
                            'body' => null,
                            'query' => [],
                            'headers' => [],
                        ];

                        return $pending;
                    }
                );

                $pending->shouldReceive('withBody')->andReturnUsing(
                    function ($body) use ($test, $pending) {
                        $test->capturedRequest->body = $body;

                        return $pending;
                    }
                );

                $pending->shouldReceive('withMultipart')->andReturnUsing(
                    function ($multipart) use ($test, $pending) {
                        $test->capturedMultipart = $multipart;

                        return $pending;
                    }
                );

                $pending->shouldReceive('withHeaders')->andReturnUsing(
                    function ($headers) use ($test, $pending) {
                        $test->capturedRequest->headers = array_merge(
                            (array) ($test->capturedRequest->headers ?? []),
                            $headers,
                        );

                        return $pending;
                    }
                );

                $pending->shouldReceive('with')->andReturnUsing(
                    function ($query) use ($test, $pending) {
                        $test->capturedRequest->query = $query;

                        return $pending;
                    }
                );

                $pending->shouldReceive('send')->andReturnUsing(function () use ($test) {
                    return $test->mockResponse;
                });

                return $pending;
            });

        $this->app->instance(ServiceClient::class, $mock);
    }

    protected function defineRoutes($router): void
    {
        $route = $router->get('/api/orders', [ProxyController::class, 'handle']);
        $route->setAction(array_merge($route->getAction(), [
            '_service' => 'oms',
            '_service_uri' => '/api/orders',
            '_service_prefix' => 'oms',
        ]));

        $route = $router->post('/api/orders', [ProxyController::class, 'handle']);
        $route->setAction(array_merge($route->getAction(), [
            '_service' => 'oms',
            '_service_uri' => '/api/orders',
            '_service_prefix' => 'oms',
        ]));

        $route = $router->get('/api/products/{product}', [ProxyController::class, 'handle']);
        $route->setAction(array_merge($route->getAction(), [
            '_service' => 'pim',
            '_service_uri' => '/api/products/{product}',
            '_service_prefix' => 'pim',
        ]));

        $route = $router->get('/api/fallback', [ProxyController::class, 'handle']);
        $route->setAction(array_merge($route->getAction(), [
            '_service' => 'oms',
        ]));

        $route = $router->post('/api/raw-body', [ProxyController::class, 'handle']);
        $route->setAction(array_merge($route->getAction(), [
            '_service' => 'oms',
            '_service_uri' => '/api/raw-body',
            '_service_prefix' => 'oms',
        ]));

        $route = $router->post('/api/import', [ProxyController::class, 'handle']);
        $route->setAction(array_merge($route->getAction(), [
            '_service' => 'pim',
            '_service_uri' => '/api/import',
            '_service_prefix' => 'pim',
        ]));
    }

    public function test_proxies_get_request(): void
    {
        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJson(['data' => 'proxied']);

        $this->assertSame('GET', $this->capturedRequest->method);
        $this->assertSame('/api/orders', $this->capturedRequest->path);
    }

    public function test_proxies_post_with_json_body(): void
    {
        $this->postJson('/api/orders', ['product_id' => 1])
            ->assertOk();

        $this->assertSame('POST', $this->capturedRequest->method);
        $this->assertSame(['product_id' => 1], $this->capturedRequest->body);
    }

    public function test_resolves_path_with_route_parameters(): void
    {
        $this->getJson('/api/products/42')
            ->assertOk();

        $this->assertSame('/api/products/42', $this->capturedRequest->path);
    }

    public function test_forwards_query_parameters(): void
    {
        $this->getJson('/api/orders?page=2&limit=10')
            ->assertOk();

        $this->assertSame('2', $this->capturedRequest->query['page']);
        $this->assertSame('10', $this->capturedRequest->query['limit']);
    }

    public function test_falls_back_to_request_path_when_service_uri_is_null(): void
    {
        $this->getJson('/api/fallback')
            ->assertOk();

        $this->assertSame('/api/fallback', $this->capturedRequest->path);
    }

    public function test_filters_transfer_encoding_and_connection_headers(): void
    {
        $this->mockResponse = new ServiceResponse(new Response(200, [
            'Content-Type' => 'application/json',
            'Transfer-Encoding' => 'chunked',
            'Connection' => 'keep-alive',
            'X-Custom' => 'preserved',
        ], '{"ok":true}'));

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $response->assertHeader('X-Custom', 'preserved');
        $response->assertHeaderMissing('Transfer-Encoding');
        $response->assertHeaderMissing('Connection');
    }

    public function test_post_with_non_json_body_sends_null(): void
    {
        $this->call('POST', '/api/raw-body', [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], 'plain text body');

        $this->assertSame('POST', $this->capturedRequest->method);
        $this->assertNull($this->capturedRequest->body);
    }

    public function test_post_with_empty_body_sends_null(): void
    {
        $this->call('POST', '/api/orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertSame('POST', $this->capturedRequest->method);
        $this->assertNull($this->capturedRequest->body);
    }

    public function test_forwards_proxy_headers_to_backend(): void
    {
        $this->getJson('/api/orders');

        $this->assertArrayHasKey('X-Forwarded-Host', $this->capturedRequest->headers);
        $this->assertArrayHasKey('X-Forwarded-Proto', $this->capturedRequest->headers);
        $this->assertArrayHasKey('X-Forwarded-Port', $this->capturedRequest->headers);
        $this->assertArrayHasKey('X-Forwarded-Prefix', $this->capturedRequest->headers);
    }

    public function test_forwards_prefix_header_from_route_metadata(): void
    {
        $this->getJson('/api/orders');

        $this->assertSame('/oms', $this->capturedRequest->headers['X-Forwarded-Prefix']);
    }

    public function test_omits_prefix_when_not_set(): void
    {
        $this->getJson('/api/fallback');

        $this->assertArrayNotHasKey('X-Forwarded-Prefix', $this->capturedRequest->headers);
    }

    public function test_proxies_multipart_with_file(): void
    {
        $file = UploadedFile::fake()->create('products.json', 100, 'application/json');

        $this->call('POST', '/api/import', ['import_type' => 'products'], [], ['import_file' => $file]);

        $this->assertSame('POST', $this->capturedRequest->method);
        $this->assertNull($this->capturedRequest->body);
        $this->assertNotNull($this->capturedMultipart);

        $names = array_column($this->capturedMultipart, 'name');
        $this->assertContains('import_type', $names);
        $this->assertContains('import_file', $names);

        $fileEntry = array_values(array_filter($this->capturedMultipart, fn ($e) => $e['name'] === 'import_file'))[0];
        $this->assertSame('products.json', $fileEntry['filename']);
        $this->assertIsResource($fileEntry['contents']);
    }

    public function test_multipart_field_values_are_forwarded(): void
    {
        $file = UploadedFile::fake()->create('data.json', 10, 'application/json');

        $this->call('POST', '/api/import', ['import_type' => 'categories'], [], ['import_file' => $file]);

        $fieldEntry = array_values(array_filter($this->capturedMultipart, fn ($e) => $e['name'] === 'import_type'))[0];
        $this->assertSame('categories', $fieldEntry['contents']);
    }
}
