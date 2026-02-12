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
use Illuminate\Support\Facades\Event;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Events\ServiceRequestFailed;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Registry\HealthRegistry;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class ServiceClientTest extends TestCase
{
    private array $history = [];

    private function createClient(array $responses, ?HealthRegistry $registry = null): ServiceClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $httpClient = new Client(['handler' => $stack]);

        if ($registry === null) {
            $registry = Mockery::mock(HealthRegistry::class);
            $registry->shouldReceive('getHealthyInstances')->andReturnUsing(function ($service) {
                return config("microservice.services.$service.base_urls", []);
            });
            $registry->shouldReceive('getInstances')->andReturnUsing(function ($service) {
                return config("microservice.services.$service.base_urls", []);
            });
            $registry->shouldReceive('markSuccess')->andReturnNull();
            $registry->shouldReceive('markFailure')->andReturnNull();
        }

        $this->app->instance(HealthRegistry::class, $registry);

        $client = $this->app->make(ServiceClient::class);

        $reflection = new \ReflectionProperty($client, 'httpClient');
        $reflection->setValue($client, $httpClient);

        return $client;
    }

    public function test_successful_request_returns_service_response(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);

        $client = $this->createClient([new Response(200, [], '{"data":"ok"}')]);

        $response = $client->service('oms')->get('/api/orders')->send();

        $this->assertTrue($response->ok());
        $this->assertSame(200, $response->status());
        $this->assertSame('ok', $response->json('data'));
    }

    public function test_request_includes_hmac_headers(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);

        $client = $this->createClient([new Response(200)]);

        $client->service('oms')->get('/api/orders')->send();

        $request = $this->history[0]['request'];
        $this->assertTrue($request->hasHeader('X-Signature'));
        $this->assertTrue($request->hasHeader('X-Timestamp'));
        $this->assertTrue($request->hasHeader('X-Service-Name'));
        $this->assertTrue($request->hasHeader('X-Request-Id'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('test-service', $request->getHeaderLine('X-Service-Name'));
    }

    public function test_4xx_response_returned_without_retry(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);
        $this->app['config']->set('microservice.defaults.retries', 2);

        $client = $this->createClient([new Response(404, [], '{"error":"not found"}')]);

        $response = $client->service('oms')->get('/api/orders/999')->send();

        $this->assertSame(404, $response->status());
        $this->assertCount(1, $this->history);
    }

    public function test_5xx_triggers_retry(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);
        $this->app['config']->set('microservice.defaults.retries', 2);

        $client = $this->createClient([
            new Response(500),
            new Response(500),
            new Response(200, [], '{"data":"ok"}'),
        ]);

        $response = $client->service('oms')->get('/api/orders')->send();

        $this->assertTrue($response->ok());
        $this->assertCount(3, $this->history);
    }

    public function test_failover_to_next_instance(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', [
            'http://oms-1:8000',
            'http://oms-2:8000',
        ]);

        $client = $this->createClient([
            new ConnectException('Connection refused', new Request('GET', 'http://oms-1:8000/api/orders')),
            new Response(200, [], '{"data":"ok"}'),
        ]);

        $response = $client->service('oms')->get('/api/orders')->retries(0)->send();

        $this->assertTrue($response->ok());
    }

    public function test_all_instances_failed_throws_unavailable(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);

        $client = $this->createClient([
            new ConnectException('Connection refused', new Request('GET', 'http://oms:8000/api/orders')),
            new ConnectException('Connection refused', new Request('GET', 'http://oms:8000/api/orders')),
            new ConnectException('Connection refused', new Request('GET', 'http://oms:8000/api/orders')),
        ]);

        $this->expectException(ServiceUnavailableException::class);

        $client->service('oms')->get('/api/orders')->send();
    }

    public function test_service_request_failed_event_dispatched(): void
    {
        Event::fake([ServiceRequestFailed::class]);

        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);
        $this->app['config']->set('microservice.defaults.retries', 0);

        $client = $this->createClient([new Response(500)]);

        try {
            $client->service('oms')->get('/api/orders')->send();
        } catch (ServiceUnavailableException) {
            // Expected
        }

        Event::assertDispatched(ServiceRequestFailed::class, function ($event) {
            return $event->service === 'oms' && $event->statusCode === 500;
        });
    }

    public function test_post_sends_json_body(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);

        $client = $this->createClient([new Response(201, [], '{"id":1}')]);

        $response = $client->service('oms')
            ->post('/api/orders', ['product_id' => 1, 'quantity' => 5])
            ->send();

        $this->assertSame(201, $response->status());

        $sentBody = (string) $this->history[0]['request']->getBody();
        $decoded = json_decode($sentBody, true);

        $this->assertSame(1, $decoded['product_id']);
        $this->assertSame(5, $decoded['quantity']);
    }

    public function test_throws_when_no_instances_configured(): void
    {
        $client = $this->createClient([]);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('No instances configured');

        $client->service('unknown')->get('/api/test')->send();
    }

    public function test_custom_request_id_header_is_preserved(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);

        $client = $this->createClient([new Response(200)]);

        $client->service('oms')
            ->get('/api/orders')
            ->withHeaders(['X-Request-Id' => 'custom-id-123'])
            ->send();

        $request = $this->history[0]['request'];
        $this->assertSame('custom-id-123', $request->getHeaderLine('X-Request-Id'));
    }

    public function test_uses_per_service_timeout_from_config(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);
        $this->app['config']->set('microservice.services.oms.timeout', 15);

        $client = $this->createClient([new Response(200)]);

        $client->service('oms')->get('/api/orders')->send();

        $options = $this->history[0]['options'];
        $this->assertSame(15, $options['timeout']);
    }

    public function test_explicit_timeout_overrides_config(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);
        $this->app['config']->set('microservice.services.oms.timeout', 15);

        $client = $this->createClient([new Response(200)]);

        $client->service('oms')->get('/api/orders')->timeout(3)->send();

        $options = $this->history[0]['options'];
        $this->assertSame(3, $options['timeout']);
    }

    public function test_falls_back_to_all_instances_when_none_healthy(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', [
            'http://oms-1:8000',
            'http://oms-2:8000',
        ]);

        $registry = Mockery::mock(HealthRegistry::class);
        $registry->shouldReceive('getHealthyInstances')->with('oms')->andReturn([]);
        $registry->shouldReceive('getInstances')->with('oms')->andReturn([
            'http://oms-1:8000',
            'http://oms-2:8000',
        ]);
        $registry->shouldReceive('markSuccess')->andReturnNull();
        $registry->shouldReceive('markFailure')->andReturnNull();

        $client = $this->createClient([new Response(200, [], '{"ok":true}')], $registry);

        $response = $client->service('oms')->get('/api/orders')->send();

        $this->assertTrue($response->ok());
    }

    public function test_mark_success_called_on_successful_request(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);

        $registry = Mockery::mock(HealthRegistry::class);
        $registry->shouldReceive('getHealthyInstances')->andReturn(['http://oms:8000']);
        $registry->shouldReceive('markSuccess')->once()->with('oms', 'http://oms:8000');

        $client = $this->createClient([new Response(200)], $registry);

        $client->service('oms')->get('/api/orders')->send();
    }

    public function test_mark_failure_called_on_failed_request(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);
        $this->app['config']->set('microservice.defaults.retries', 0);

        $registry = Mockery::mock(HealthRegistry::class);
        $registry->shouldReceive('getHealthyInstances')->andReturn(['http://oms:8000']);
        $registry->shouldReceive('getInstances')->andReturn(['http://oms:8000']);
        $registry->shouldReceive('markFailure')->once()->with('oms', 'http://oms:8000');

        $client = $this->createClient([new Response(500)], $registry);

        try {
            $client->service('oms')->get('/api/orders')->send();
        } catch (ServiceUnavailableException) {
            // Expected
        }
    }

    public function test_query_parameters_are_sent(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);

        $client = $this->createClient([new Response(200)]);

        $client->service('oms')
            ->get('/api/orders')
            ->withQuery(['page' => 2, 'limit' => 10])
            ->send();

        $uri = (string) $this->history[0]['request']->getUri();
        $this->assertStringContainsString('page=2', $uri);
        $this->assertStringContainsString('limit=10', $uri);
    }
}
