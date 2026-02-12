<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Redis\Connections\Connection;
use Jurager\Microservice\Registry\RouteRegistry;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class RouteRegistryTest extends TestCase
{
    private Connection $redis;

    private RouteRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = Mockery::mock(Connection::class);

        $this->registry = Mockery::mock(RouteRegistry::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $this->registry->shouldReceive('redis')->andReturn($this->redis);
    }

    public function test_get_all_manifests_returns_manifests_from_redis(): void
    {
        $manifest = json_encode([
            'service' => 'pim',
            'routes' => [['method' => 'GET', 'uri' => '/api/products']],
        ]);

        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(['pim']);

        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn($manifest);

        $result = $this->registry->getAllManifests();

        $this->assertArrayHasKey('pim', $result);
        $this->assertSame('pim', $result['pim']['service']);
    }

    public function test_get_all_manifests_returns_empty_when_no_keys(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn([]);

        $this->assertEmpty($this->registry->getAllManifests());
    }

    public function test_get_all_routes_flattens_manifests(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(['pim', 'oms']);

        $this->redis->shouldReceive('get')
            ->with(Mockery::pattern('/manifest:pim$/'))
            ->andReturn(json_encode([
                'service' => 'pim',
                'routes' => [
                    ['method' => 'GET', 'uri' => '/api/products', 'name' => 'products.index'],
                ],
            ]));

        $this->redis->shouldReceive('get')
            ->with(Mockery::pattern('/manifest:oms$/'))
            ->andReturn(json_encode([
                'service' => 'oms',
                'routes' => [
                    ['method' => 'POST', 'uri' => '/api/orders', 'name' => 'orders.store'],
                ],
            ]));

        $routes = $this->registry->getAllRoutes();

        $this->assertCount(2, $routes);

        $services = array_column($routes, 'service');
        $this->assertContains('pim', $services);
        $this->assertContains('oms', $services);
    }

    public function test_resolve_matches_exact_uri(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(['pim']);

        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn(json_encode([
                'service' => 'pim',
                'routes' => [
                    ['method' => 'GET', 'uri' => '/api/products'],
                ],
            ]));

        $match = $this->registry->resolve('GET', '/api/products');

        $this->assertNotNull($match);
        $this->assertSame('pim', $match['service']);
    }

    public function test_resolve_matches_parameterized_uri(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(['pim']);

        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn(json_encode([
                'service' => 'pim',
                'routes' => [
                    ['method' => 'GET', 'uri' => '/api/products/{product}'],
                ],
            ]));

        $match = $this->registry->resolve('GET', '/api/products/123');

        $this->assertNotNull($match);
        $this->assertSame('pim', $match['service']);
    }

    public function test_resolve_returns_null_for_no_match(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(['pim']);

        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn(json_encode([
                'service' => 'pim',
                'routes' => [
                    ['method' => 'GET', 'uri' => '/api/products'],
                ],
            ]));

        $this->assertNull($this->registry->resolve('GET', '/api/unknown'));
    }

    public function test_resolve_respects_http_method(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(['oms']);

        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn(json_encode([
                'service' => 'oms',
                'routes' => [
                    ['method' => 'POST', 'uri' => '/api/orders'],
                ],
            ]));

        $this->assertNull($this->registry->resolve('GET', '/api/orders'));
    }

    public function test_get_all_manifests_returns_multiple_services(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(['a', 'b', 'c']);

        $this->redis->shouldReceive('get')
            ->with(Mockery::pattern('/manifest:a$/'))
            ->andReturn(json_encode(['service' => 'a', 'routes' => []]));

        $this->redis->shouldReceive('get')
            ->with(Mockery::pattern('/manifest:b$/'))
            ->andReturn(json_encode(['service' => 'b', 'routes' => []]));

        $this->redis->shouldReceive('get')
            ->with(Mockery::pattern('/manifest:c$/'))
            ->andReturn(json_encode(['service' => 'c', 'routes' => []]));

        $manifests = $this->registry->getAllManifests();

        $this->assertCount(3, $manifests);
    }

    public function test_get_all_manifests_skips_null_and_false_values(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(['pim', 'gone1', 'gone2']);

        $this->redis->shouldReceive('get')
            ->with(Mockery::pattern('/manifest:pim$/'))
            ->andReturn(json_encode(['service' => 'pim', 'routes' => []]));

        $this->redis->shouldReceive('get')
            ->with(Mockery::pattern('/manifest:gone1$/'))
            ->andReturn(null);

        $this->redis->shouldReceive('get')
            ->with(Mockery::pattern('/manifest:gone2$/'))
            ->andReturn(false);

        $manifests = $this->registry->getAllManifests();

        $this->assertCount(1, $manifests);
        $this->assertArrayHasKey('pim', $manifests);
    }

    public function test_get_all_manifests_skips_invalid_json(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(['bad', 'oms']);

        $this->redis->shouldReceive('get')
            ->with(Mockery::pattern('/manifest:bad$/'))
            ->andReturn('not-valid-json{{{');

        $this->redis->shouldReceive('get')
            ->with(Mockery::pattern('/manifest:oms$/'))
            ->andReturn(json_encode(['service' => 'oms', 'routes' => []]));

        $manifests = $this->registry->getAllManifests();

        $this->assertCount(1, $manifests);
        $this->assertArrayHasKey('oms', $manifests);
    }

    public function test_get_all_manifests_skips_data_without_service_key(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(['noservice']);

        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn(json_encode(['routes' => []]));

        $this->assertEmpty($this->registry->getAllManifests());
    }

    public function test_resolve_normalizes_lowercase_method(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(['pim']);

        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn(json_encode([
                'service' => 'pim',
                'routes' => [
                    ['method' => 'GET', 'uri' => '/api/products'],
                ],
            ]));

        $match = $this->registry->resolve('get', '/api/products');

        $this->assertNotNull($match);
        $this->assertSame('pim', $match['service']);
    }

    public function test_resolve_normalizes_uri_leading_slash(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(['pim']);

        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn(json_encode([
                'service' => 'pim',
                'routes' => [
                    ['method' => 'GET', 'uri' => '/api/products'],
                ],
            ]));

        $match = $this->registry->resolve('GET', 'api/products');

        $this->assertNotNull($match);
    }

    public function test_get_all_manifests_sorts_by_service_name(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(['zzz', 'aaa']);

        $this->redis->shouldReceive('get')
            ->with(Mockery::pattern('/manifest:zzz$/'))
            ->andReturn(json_encode(['service' => 'zzz', 'routes' => []]));

        $this->redis->shouldReceive('get')
            ->with(Mockery::pattern('/manifest:aaa$/'))
            ->andReturn(json_encode(['service' => 'aaa', 'routes' => []]));

        $manifests = $this->registry->getAllManifests();
        $keys = array_keys($manifests);

        $this->assertSame('aaa', $keys[0]);
        $this->assertSame('zzz', $keys[1]);
    }

    public function test_smembers_returns_false_treated_as_empty(): void
    {
        $this->redis->shouldReceive('smembers')
            ->once()
            ->andReturn(false);

        $this->assertEmpty($this->registry->getAllManifests());
    }
}
