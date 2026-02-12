<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Redis\Connections\Connection;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Client\ServiceResponse;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Registry\ManifestRegistry;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class RegisterManifestCommandTest extends TestCase
{
    public function test_registers_manifest_locally_without_gateway(): void
    {
        $redis = Mockery::mock(Connection::class);
        $redis->shouldReceive('setex')->once();
        $redis->shouldReceive('sadd')->once();

        $registry = Mockery::mock(ManifestRegistry::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('redis')->andReturn($redis);
        $registry->shouldReceive('build')->once()->andReturn([
            'service' => 'test-service',
            'routes' => [
                ['method' => 'GET', 'uri' => '/api/products', 'name' => 'products.index'],
            ],
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->app->instance(ManifestRegistry::class, $registry);

        $this->artisan('microservice:register')
            ->assertSuccessful()
            ->expectsOutputToContain('1 route(s) registered');
    }

    public function test_pushes_manifest_to_gateway(): void
    {
        $this->app['config']->set('microservice.manifest.gateway', 'gateway');
        $this->app['config']->set('microservice.services.gateway.base_urls', ['http://gateway:8000']);

        $mockResponse = new ServiceResponse(new Response(200, [], '{"status":"registered"}'));

        $pending = Mockery::mock(\Jurager\Microservice\Client\PendingServiceRequest::class);
        $pending->shouldReceive('post')->andReturn($pending);
        $pending->shouldReceive('send')->andReturn($mockResponse);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('service')->with('gateway')->andReturn($pending);
        $this->app->instance(ServiceClient::class, $client);

        $registry = Mockery::mock(ManifestRegistry::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('build')->once()->andReturn([
            'service' => 'test-service',
            'routes' => [
                ['method' => 'GET', 'uri' => '/api/products', 'name' => 'products.index'],
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
        $this->app->instance(ManifestRegistry::class, $registry);

        $this->artisan('microservice:register')
            ->assertSuccessful();
    }

    public function test_fails_when_gateway_unavailable(): void
    {
        $this->app['config']->set('microservice.manifest.gateway', 'gateway');

        $pending = Mockery::mock(\Jurager\Microservice\Client\PendingServiceRequest::class);
        $pending->shouldReceive('post')->andReturn($pending);
        $pending->shouldReceive('send')->andThrow(new ServiceUnavailableException('gateway'));

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('service')->with('gateway')->andReturn($pending);
        $this->app->instance(ServiceClient::class, $client);

        $registry = Mockery::mock(ManifestRegistry::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('build')->once()->andReturn([
            'service' => 'test-service',
            'routes' => [],
            'timestamp' => now()->toIso8601String(),
        ]);
        $this->app->instance(ManifestRegistry::class, $registry);

        $this->artisan('microservice:register')
            ->assertFailed();
    }

    public function test_fails_when_gateway_returns_error_response(): void
    {
        $this->app['config']->set('microservice.manifest.gateway', 'gateway');
        $this->app['config']->set('microservice.services.gateway.base_urls', ['http://gateway:8000']);

        $mockResponse = new ServiceResponse(new Response(500, [], '{"error":"internal"}'));

        $pending = Mockery::mock(\Jurager\Microservice\Client\PendingServiceRequest::class);
        $pending->shouldReceive('post')->andReturn($pending);
        $pending->shouldReceive('send')->andReturn($mockResponse);

        $client = Mockery::mock(ServiceClient::class);
        $client->shouldReceive('service')->with('gateway')->andReturn($pending);
        $this->app->instance(ServiceClient::class, $client);

        $registry = Mockery::mock(ManifestRegistry::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('build')->once()->andReturn([
            'service' => 'test-service',
            'routes' => [
                ['method' => 'GET', 'uri' => '/api/products', 'name' => 'products.index'],
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
        $this->app->instance(ManifestRegistry::class, $registry);

        $this->artisan('microservice:register')
            ->assertFailed();
    }
}
