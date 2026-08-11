<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Cache\Repository as Cache;
use Jurager\Microservice\Registry\RouteRegistry;
use Jurager\Microservice\Tests\TestCase;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;

class RouteRegistryTest extends TestCase
{
    private Cache $cache;

    private LoggerInterface $logger;

    private RouteRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = Mockery::mock(Cache::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->registry = new RouteRegistry($this->cache, $this->logger);
    }

    public function test_get_all_manifests_returns_manifests_from_redis(): void
    {
        $manifest = json_encode([
            'service' => 'pim',
            'routes' => [['method' => 'GET', 'uri' => '/api/products']],
        ]);

        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['pim']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:pim'])
            ->andReturn([$manifest]);

        $result = $this->registry->getAllManifests();

        $this->assertArrayHasKey('pim', $result);
        $this->assertSame('pim', $result['pim']['service']);
    }

    public function test_get_all_manifests_returns_empty_when_no_keys(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn([]);

        $this->assertEmpty($this->registry->getAllManifests());
    }

    public function test_get_all_routes_flattens_manifests(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['pim', 'oms']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:pim', 'microservice:manifest:oms'])
            ->andReturn([
                json_encode([
                    'service' => 'pim',
                    'routes' => [
                        ['method' => 'GET', 'uri' => '/api/products', 'name' => 'products.index'],
                    ],
                ]),
                json_encode([
                    'service' => 'oms',
                    'routes' => [
                        ['method' => 'POST', 'uri' => '/api/orders', 'name' => 'orders.store'],
                    ],
                ]),
            ]);

        $routes = $this->registry->getAllRoutes();

        $this->assertCount(2, $routes);

        $services = array_column($routes, 'service');
        $this->assertContains('pim', $services);
        $this->assertContains('oms', $services);
    }

    public function test_get_all_routes_exposes_every_method_of_an_entry(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['oms']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:oms'])
            ->andReturn([json_encode([
                'service' => 'oms',
                'routes' => [
                    ['methods' => ['GET', 'POST'], 'uri' => '/api/attributes', 'name' => 'attributes.index'],
                ],
            ])]);

        $routes = $this->registry->getAllRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame(['GET', 'POST'], $routes[0]['methods']);
    }

    public function test_resolve_matches_any_method_of_a_multi_method_route(): void
    {
        $manifest = json_encode([
            'service' => 'oms',
            'routes' => [
                ['methods' => ['GET', 'POST'], 'uri' => '/api/attributes'],
            ],
        ]);

        $this->cache->shouldReceive('get')
            ->times(3)
            ->with('microservice:manifests', [])
            ->andReturn(['oms']);

        $this->cache->shouldReceive('many')
            ->times(3)
            ->with(['microservice:manifest:oms'])
            ->andReturn([$manifest]);

        $this->assertNotNull($this->registry->resolve('GET', '/api/attributes'));
        $this->assertNotNull($this->registry->resolve('POST', '/api/attributes'));
        $this->assertNull($this->registry->resolve('DELETE', '/api/attributes'));
    }

    public function test_resolve_matches_exact_uri(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['pim']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:pim'])
            ->andReturn([json_encode([
                'service' => 'pim',
                'routes' => [
                    ['method' => 'GET', 'uri' => '/api/products'],
                ],
            ])]);

        $match = $this->registry->resolve('GET', '/api/products');

        $this->assertNotNull($match);
        $this->assertSame('pim', $match['service']);
    }

    public function test_resolve_matches_parameterized_uri(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['pim']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:pim'])
            ->andReturn([json_encode([
                'service' => 'pim',
                'routes' => [
                    ['method' => 'GET', 'uri' => '/api/products/{product}'],
                ],
            ])]);

        $match = $this->registry->resolve('GET', '/api/products/123');

        $this->assertNotNull($match);
        $this->assertSame('pim', $match['service']);
    }

    public function test_resolve_returns_null_for_no_match(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['pim']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:pim'])
            ->andReturn([json_encode([
                'service' => 'pim',
                'routes' => [
                    ['method' => 'GET', 'uri' => '/api/products'],
                ],
            ])]);

        $this->assertNull($this->registry->resolve('GET', '/api/unknown'));
    }

    public function test_resolve_respects_http_method(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['oms']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:oms'])
            ->andReturn([json_encode([
                'service' => 'oms',
                'routes' => [
                    ['method' => 'POST', 'uri' => '/api/orders'],
                ],
            ])]);

        $this->assertNull($this->registry->resolve('GET', '/api/orders'));
    }

    public function test_get_all_manifests_returns_multiple_services(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['a', 'b', 'c']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:a', 'microservice:manifest:b', 'microservice:manifest:c'])
            ->andReturn([
                json_encode(['service' => 'a', 'routes' => []]),
                json_encode(['service' => 'b', 'routes' => []]),
                json_encode(['service' => 'c', 'routes' => []]),
            ]);

        $manifests = $this->registry->getAllManifests();

        $this->assertCount(3, $manifests);
    }

    public function test_get_all_manifests_skips_null_and_false_values(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['pim', 'gone1', 'gone2']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:pim', 'microservice:manifest:gone1', 'microservice:manifest:gone2'])
            ->andReturn([
                json_encode(['service' => 'pim', 'routes' => []]),
                null,
                false,
            ]);

        $manifests = $this->registry->getAllManifests();

        $this->assertCount(1, $manifests);
        $this->assertArrayHasKey('pim', $manifests);
    }

    public function test_get_all_manifests_skips_invalid_json(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['bad', 'oms']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:bad', 'microservice:manifest:oms'])
            ->andReturn([
                'not-valid-json{{{',
                json_encode(['service' => 'oms', 'routes' => []]),
            ]);

        $manifests = $this->registry->getAllManifests();

        $this->assertCount(1, $manifests);
        $this->assertArrayHasKey('oms', $manifests);
    }

    public function test_get_all_manifests_skips_data_without_service_key(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['noservice']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:noservice'])
            ->andReturn([json_encode(['routes' => []])]);

        $this->assertEmpty($this->registry->getAllManifests());
    }

    public function test_resolve_normalizes_lowercase_method(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['pim']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:pim'])
            ->andReturn([json_encode([
                'service' => 'pim',
                'routes' => [
                    ['method' => 'GET', 'uri' => '/api/products'],
                ],
            ])]);

        $match = $this->registry->resolve('get', '/api/products');

        $this->assertNotNull($match);
        $this->assertSame('pim', $match['service']);
    }

    public function test_resolve_normalizes_uri_leading_slash(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['pim']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:pim'])
            ->andReturn([json_encode([
                'service' => 'pim',
                'routes' => [
                    ['method' => 'GET', 'uri' => '/api/products'],
                ],
            ])]);

        $match = $this->registry->resolve('GET', 'api/products');

        $this->assertNotNull($match);
    }

    public function test_get_all_manifests_sorts_by_service_name(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['zzz', 'aaa']);

        $this->cache->shouldReceive('many')
            ->once()
            ->with(['microservice:manifest:zzz', 'microservice:manifest:aaa'])
            ->andReturn([
                json_encode(['service' => 'zzz', 'routes' => []]),
                json_encode(['service' => 'aaa', 'routes' => []]),
            ]);

        $manifests = $this->registry->getAllManifests();
        $keys = array_keys($manifests);

        $this->assertSame('aaa', $keys[0]);
        $this->assertSame('zzz', $keys[1]);
    }

    public function test_get_returns_false_treated_as_empty(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(false);

        $this->assertEmpty($this->registry->getAllManifests());
    }

    public function test_unreachable_cache_is_treated_as_empty(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andThrow(new RuntimeException('Connection refused'));

        $this->logger->shouldReceive('warning')
            ->once()
            ->with(Mockery::type('string'), ['error' => 'Connection refused']);

        $this->assertEmpty($this->registry->getAllManifests());
    }

    public function test_unreachable_cache_while_reading_manifests_is_treated_as_empty(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->with('microservice:manifests', [])
            ->andReturn(['pim']);

        $this->cache->shouldReceive('many')
            ->once()
            ->andThrow(new RuntimeException('Connection refused'));

        $this->logger->shouldReceive('warning')->once();

        $this->assertEmpty($this->registry->getAllManifests());
    }
}
