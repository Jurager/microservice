<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Manifest\ManifestBuilder;
use Jurager\Microservice\Manifest\ManifestRepository;
use Jurager\Microservice\Tests\TestCase;
use Mockery;
use Psr\Log\LoggerInterface;

class ManifestBuilderTest extends TestCase
{
    private Cache $cache;

    private ManifestBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = Mockery::mock(Cache::class);
        $this->builder = $this->app->make(ManifestBuilder::class);
    }

    /** A repository over the mocked cache, with the manifest TTL configured at the time of the call. */
    private function repository(): ManifestRepository
    {
        return new ManifestRepository($this->cache, Mockery::mock(LoggerInterface::class), (int) config('microservice.manifest.ttl'));
    }

    public function test_build_returns_manifest_with_service_and_routes(): void
    {
        Route::get('api/products', fn () => 'ok')->name('products.index');
        Route::post('api/products', fn () => 'ok')->name('products.store');

        $manifest = $this->builder->build();

        $this->assertSame('test-service', $manifest['service']);
        $this->assertNotEmpty($manifest['routes']);
        $this->assertArrayHasKey('timestamp', $manifest);
        $this->assertArrayHasKey('base_url', $manifest);

        $methods = array_merge(...array_column($manifest['routes'], 'methods'));
        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
    }

    public function test_build_keeps_a_multi_method_route_as_a_single_entry(): void
    {
        Route::match(['get', 'post'], 'api/attributes', fn () => 'ok')->name('attributes.index');

        $manifest = $this->builder->build();

        $entries = array_values(array_filter(
            $manifest['routes'],
            static fn (array $route) => $route['name'] === 'attributes.index',
        ));

        $this->assertCount(1, $entries);
        $this->assertSame(['GET', 'POST'], $entries[0]['methods']);
    }

    public function test_build_filters_routes_by_prefix(): void
    {
        Route::get('api/products', fn () => 'ok');
        Route::get('admin/settings', fn () => 'ok');

        $manifest = $this->builder->build();

        $uris = array_column($manifest['routes'], 'uri');

        $this->assertContains('/api/products', $uris);
        $this->assertNotContains('/admin/settings', $uris);
    }

    public function test_build_excludes_head_method(): void
    {
        Route::get('api/products', fn () => 'ok');

        $manifest = $this->builder->build();

        $methods = array_merge(...array_column($manifest['routes'], 'methods'));

        $this->assertNotContains('HEAD', $methods);
    }

    public function test_store_writes_manifest_to_redis(): void
    {
        $manifest = [
            'service' => 'pim',
            'routes' => [['method' => 'GET', 'uri' => '/api/products']],
            'timestamp' => now()->toIso8601String(),
        ];

        $this->cache->shouldReceive('put')
            ->once()
            ->withArgs(function ($key, $value) {
                return $key === 'microservice:manifest:pim'
                    && isset($value['synced_at']);
            });

        $this->cache->shouldReceive('lock')
            ->once()
            ->with('microservice:manifests_lock', 10)
            ->andReturn($lock = Mockery::mock());

        $lock->shouldReceive('block')
            ->once()
            ->withArgs(function ($seconds, $callback) {
                // Mock the callback execution
                $this->cache->shouldReceive('get')->with('microservice:manifests', [])->andReturn([]);
                $this->cache->shouldReceive('put')->with('microservice:manifests', ['pim']);
                $callback();

                return true;
            });

        $this->repository()->store($manifest);
    }

    public function test_store_applies_the_configured_ttl(): void
    {
        config(['microservice.manifest.ttl' => 600]);

        $this->cache->shouldReceive('put')
            ->once()
            ->withArgs(fn ($key, $value, $ttl) => $key === 'microservice:manifest:pim' && $ttl === 600);

        $this->cache->shouldReceive('lock')->once()->andReturn($lock = Mockery::mock());
        $lock->shouldReceive('block')->once();

        $this->repository()->store(['service' => 'pim', 'routes' => []]);
    }

    public function test_store_keeps_the_manifest_forever_when_ttl_is_zero(): void
    {
        config(['microservice.manifest.ttl' => 0]);

        $this->cache->shouldReceive('put')
            ->once()
            ->withArgs(fn ($key, $value, $ttl) => $key === 'microservice:manifest:pim' && $ttl === null);

        $this->cache->shouldReceive('lock')->once()->andReturn($lock = Mockery::mock());
        $lock->shouldReceive('block')->once();

        $this->repository()->store(['service' => 'pim', 'routes' => []]);
    }

    public function test_store_ignores_manifest_without_service(): void
    {
        $this->cache->shouldNotReceive('put');

        $this->repository()->store(['routes' => []]);
    }

    public function test_build_includes_route_metadata(): void
    {
        Route::get('api/products', fn () => 'ok')
            ->name('products.index')
            ->metadata(['permissions' => ['products.view'], 'rate_limit' => 100]);

        $manifest = $this->builder->build();

        $found = collect($manifest['routes'])->firstWhere('name', 'products.index');

        $this->assertNotNull($found);
        $this->assertSame(['products.view'], $found['permissions']);
        $this->assertSame(100, $found['rate_limit']);
    }

    public function test_build_adds_leading_slash_to_uri(): void
    {
        Route::get('api/products', fn () => 'ok');

        $manifest = $this->builder->build();

        $uris = array_column($manifest['routes'], 'uri');

        $this->assertContains('/api/products', $uris);
    }
}
