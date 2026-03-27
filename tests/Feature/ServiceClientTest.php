<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Redis\Connections\Connection;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Support\HmacSigner;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class ServiceClientTest extends TestCase
{
    private array $history = [];

    private function createClient(array $responses, ?Connection $redis = null): ServiceClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $httpClient = new Client(['handler' => $stack]);

        $client = Mockery::mock(ServiceClient::class, [$this->app->make(HmacSigner::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        if ($redis !== null) {
            $client->shouldReceive('redis')->andReturn($redis);
        }

        $reflection = new \ReflectionProperty($client, 'httpClient');
        $reflection->setValue($client, $httpClient);

        return $client;
    }

    private function mockRedisWithManifest(string $service, string $baseUrl, ?int $timeout = null): Connection
    {
        $manifest = json_encode([
            'service' => $service,
            'base_url' => $baseUrl,
            'timeout' => $timeout,
            'routes' => [],
        ]);

        $redis = Mockery::mock(Connection::class);
        $redis->shouldReceive('get')
            ->with(config('microservice.redis.prefix', 'microservice:')."manifest:$service")
            ->andReturn($manifest);

        return $redis;
    }

    public function test_successful_request_returns_service_response(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}:8000');

        $client = $this->createClient([new Response(200, [], '{"data":"ok"}')]);

        $response = $client->service('oms')->get('/api/orders')->send();

        $this->assertTrue($response->ok());
        $this->assertSame(200, $response->status());
        $this->assertSame('ok', $response->json('data'));
    }

    public function test_request_includes_hmac_headers(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}:8000');

        $client = $this->createClient([new Response(200)]);

        $client->service('oms')->get('/api/orders')->send();

        $request = $this->history[0]['request'];
        $this->assertTrue($request->hasHeader('X-Signature'));
        $this->assertTrue($request->hasHeader('X-Timestamp'));
        $this->assertTrue($request->hasHeader('X-Service-Name'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('test-service', $request->getHeaderLine('X-Service-Name'));
    }

    public function test_forwards_custom_headers(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}:8000');

        $client = $this->createClient([new Response(200)]);

        $requestId = '550e8400-e29b-41d4-a716-446655440000';
        $client->service('oms')
            ->get('/api/orders')
            ->withHeaders(['X-Request-Id' => $requestId])
            ->send();

        $request = $this->history[0]['request'];
        $this->assertSame($requestId, $request->getHeaderLine('X-Request-Id'));
    }

    public function test_4xx_response_returned_directly(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}:8000');

        $client = $this->createClient([new Response(404, [], '{"error":"not found"}')]);

        $response = $client->service('oms')->get('/api/orders/999')->send();

        $this->assertSame(404, $response->status());
        $this->assertCount(1, $this->history);
    }

    public function test_5xx_response_returned_directly(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}:8000');

        $client = $this->createClient([new Response(500, [], '{"message":"error"}')]);

        $response = $client->service('oms')->get('/api/orders')->send();

        $this->assertSame(500, $response->status());
        $this->assertCount(1, $this->history);
    }

    public function test_connect_exception_throws_service_unavailable(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}:8000');

        $client = $this->createClient([
            new ConnectException('Connection refused', new Request('GET', 'http://oms:8000/api/orders')),
        ]);

        $this->expectException(ServiceUnavailableException::class);

        $client->service('oms')->get('/api/orders')->send();
    }

    public function test_post_sends_json_body(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}:8000');

        $client = $this->createClient([new Response(201, [], '{"id":1}')]);

        $client->service('oms')
            ->post('/api/orders', ['product_id' => 1, 'quantity' => 5])
            ->send();

        $sentBody = (string) $this->history[0]['request']->getBody();
        $decoded = json_decode($sentBody, true);

        $this->assertSame(1, $decoded['product_id']);
        $this->assertSame(5, $decoded['quantity']);
    }

    public function test_query_parameters_are_sent(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}:8000');

        $client = $this->createClient([new Response(200)]);

        $client->service('oms')
            ->get('/api/orders')
            ->withQuery(['page' => 2, 'limit' => 10])
            ->send();

        $uri = (string) $this->history[0]['request']->getUri();
        $this->assertStringContainsString('page=2', $uri);
        $this->assertStringContainsString('limit=10', $uri);
    }

    public function test_resolves_url_from_dns_pattern(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}.internal:8000');

        $client = $this->createClient([new Response(200)]);

        $client->service('oms')->get('/api/orders')->send();

        $uri = (string) $this->history[0]['request']->getUri();
        $this->assertStringStartsWith('http://oms.internal:8000', $uri);
    }

    public function test_resolves_url_from_redis_manifest(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', null);

        $redis = $this->mockRedisWithManifest('oms', 'http://oms-from-redis:9000');
        $client = $this->createClient([new Response(200)], $redis);

        $client->service('oms')->get('/api/orders')->send();

        $uri = (string) $this->history[0]['request']->getUri();
        $this->assertStringStartsWith('http://oms-from-redis:9000', $uri);
    }

    public function test_throws_when_url_cannot_be_resolved(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', null);

        $redis = Mockery::mock(Connection::class);
        $redis->shouldReceive('get')->andReturn(null);

        $client = $this->createClient([], $redis);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Cannot resolve base URL/');

        $client->service('unknown')->get('/api/test')->send();
    }

    public function test_uses_timeout_from_manifest(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', null);

        $redis = $this->mockRedisWithManifest('oms', 'http://oms:8000', 15);
        $client = $this->createClient([new Response(200)], $redis);

        $client->service('oms')->get('/api/orders')->send();

        $this->assertSame(15, $this->history[0]['options']['timeout']);
    }

    public function test_explicit_timeout_overrides_manifest(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', null);

        $redis = $this->mockRedisWithManifest('oms', 'http://oms:8000', 15);
        $client = $this->createClient([new Response(200)], $redis);

        $client->service('oms')->get('/api/orders')->timeout(3)->send();

        $this->assertSame(3, $this->history[0]['options']['timeout']);
    }

    public function test_falls_back_to_default_timeout(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}:8000');

        $client = $this->createClient([new Response(200)]);

        $client->service('oms')->get('/api/orders')->send();

        $this->assertSame(30, $this->history[0]['options']['timeout']);
    }

    public function test_multipart_request_sends_multipart_form_data(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}:8000');

        $client = $this->createClient([new Response(201, [], '{"id":1}')]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, '{"products":[]}');
        rewind($stream);

        $client->service('pim')
            ->post('/api/import')
            ->withMultipart([
                ['name' => 'import_type', 'contents' => 'products'],
                ['name' => 'import_file', 'contents' => $stream, 'filename' => 'products.json'],
            ])
            ->send();

        $request = $this->history[0]['request'];
        $this->assertStringContainsString('multipart/form-data', $request->getHeaderLine('Content-Type'));
        $this->assertArrayNotHasKey('body', $this->history[0]['options']);
    }

    public function test_multipart_request_does_not_set_json_content_type(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}:8000');

        $client = $this->createClient([new Response(201)]);

        $stream = fopen('php://temp', 'r');

        $client->service('pim')
            ->post('/api/import')
            ->withMultipart([
                ['name' => 'import_type', 'contents' => 'products'],
                ['name' => 'import_file', 'contents' => $stream, 'filename' => 'data.json'],
            ])
            ->send();

        $request = $this->history[0]['request'];
        $this->assertNotSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function test_multipart_request_includes_hmac_headers(): void
    {
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}:8000');

        $client = $this->createClient([new Response(201)]);

        $stream = fopen('php://temp', 'r');

        $client->service('pim')
            ->post('/api/import')
            ->withMultipart([
                ['name' => 'import_type', 'contents' => 'products'],
                ['name' => 'import_file', 'contents' => $stream, 'filename' => 'data.json'],
            ])
            ->send();

        $request = $this->history[0]['request'];
        $this->assertTrue($request->hasHeader('X-Signature'));
        $this->assertTrue($request->hasHeader('X-Timestamp'));
        $this->assertTrue($request->hasHeader('X-Service-Name'));
    }
}
