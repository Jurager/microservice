<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Registry\ManifestRegistry;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class ManifestRegistryTest extends TestCase
{
    private Connection $redis;

    private ManifestRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = Mockery::mock(Connection::class);

        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->andReturn($this->redis);

        $this->registry = new ManifestRegistry($factory);
    }

    public function test_build_returns_manifest_with_service_and_routes(): void
    {
        Route::get('api/products', fn () => 'ok')->name('products.index');
        Route::post('api/products', fn () => 'ok')->name('products.store');

        $manifest = $this->registry->build();

        $this->assertSame('test-service', $manifest['service']);
        $this->assertNotEmpty($manifest['routes']);
        $this->assertArrayHasKey('timestamp', $manifest);
        $this->assertArrayHasKey('base_url', $manifest);

        $methods = array_column($manifest['routes'], 'method');
        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
    }

    public function test_build_filters_routes_by_prefix(): void
    {
        Route::get('api/products', fn () => 'ok');
        Route::get('admin/settings', fn () => 'ok');

        $manifest = $this->registry->build();

        $uris = array_column($manifest['routes'], 'uri');

        $this->assertContains('/api/products', $uris);
        $this->assertNotContains('/admin/settings', $uris);
    }

    public function test_build_excludes_head_method(): void
    {
        Route::get('api/products', fn () => 'ok');

        $manifest = $this->registry->build();

        $methods = array_column($manifest['routes'], 'method');

        $this->assertNotContains('HEAD', $methods);
    }

    public function test_store_writes_manifest_to_redis(): void
    {
        $manifest = [
            'service' => 'pim',
            'routes' => [['method' => 'GET', 'uri' => '/api/products']],
            'timestamp' => now()->toIso8601String(),
        ];

        // ttl 300 with a 5-minute sync interval is floored to two sync cycles (600s)
        // so a single late sync cannot evict the manifest.
        $this->redis->shouldReceive('setex')->once()->withArgs(function ($key, $ttl, $value) {
            $decoded = json_decode($value, true);

            return str_contains($key, 'manifest:pim')
                && $ttl === 600
                && isset($decoded['synced_at']);
        });

        $this->redis->shouldReceive('sadd')->once();

        $this->registry->store($manifest);
    }

    public function test_ttl_floors_to_two_sync_cycles_when_configured_ttl_is_too_short(): void
    {
        config([
            'microservice.manifest.ttl' => 300,
            'microservice.manifest.sync_interval' => 5,
        ]);

        $this->assertSame(600, $this->registry->ttl());
    }

    public function test_ttl_honors_configured_value_when_larger_than_sync_window(): void
    {
        config([
            'microservice.manifest.ttl' => 1800,
            'microservice.manifest.sync_interval' => 5,
        ]);

        $this->assertSame(1800, $this->registry->ttl());
    }

    public function test_ttl_uses_configured_value_when_syncing_disabled(): void
    {
        config([
            'microservice.manifest.ttl' => 300,
            'microservice.manifest.sync_interval' => 0,
        ]);

        $this->assertSame(300, $this->registry->ttl());
    }

    public function test_store_ignores_manifest_without_service(): void
    {
        $this->redis->shouldNotReceive('setex');

        $this->registry->store(['routes' => []]);
    }

    public function test_build_includes_route_metadata(): void
    {
        Route::get('api/products', fn () => 'ok')
            ->name('products.index')
            ->metadata(['permissions' => ['products.view'], 'rate_limit' => 100]);

        $manifest = $this->registry->build();

        $found = collect($manifest['routes'])->firstWhere('name', 'products.index');

        $this->assertNotNull($found);
        $this->assertSame(['products.view'], $found['permissions']);
        $this->assertSame(100, $found['rate_limit']);
    }

    public function test_build_adds_leading_slash_to_uri(): void
    {
        Route::get('api/products', fn () => 'ok');

        $manifest = $this->registry->build();

        $uris = array_column($manifest['routes'], 'uri');

        $this->assertContains('/api/products', $uris);
    }
}
