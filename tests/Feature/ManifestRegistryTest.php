<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

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

        $this->registry = Mockery::mock(ManifestRegistry::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $this->registry->shouldReceive('redis')->andReturn($this->redis);
    }

    public function test_build_returns_manifest_with_service_and_routes(): void
    {
        Route::get('api/products', fn () => 'ok')->name('products.index');
        Route::post('api/products', fn () => 'ok')->name('products.store');

        $manifest = $this->registry->build();

        $this->assertSame('test-service', $manifest['service']);
        $this->assertNotEmpty($manifest['routes']);
        $this->assertArrayHasKey('timestamp', $manifest);

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

        $this->redis->shouldReceive('setex')->once()->withArgs(function ($key, $ttl, $value) {
            return str_contains($key, 'manifest:pim') && $ttl === 300;
        });

        $this->redis->shouldReceive('sadd')->once();

        $this->registry->store($manifest);
    }

    public function test_store_ignores_manifest_without_service(): void
    {
        $this->redis->shouldNotReceive('setex');

        $this->registry->store(['routes' => []]);
    }

    public function test_build_includes_route_metadata(): void
    {
        $route = Route::get('api/products', fn () => 'ok')->name('products.index');
        $route->setAction(array_merge($route->getAction(), [
            'permissions' => ['products.view'],
            'rate_limit' => 100,
        ]));

        $manifest = $this->registry->build();

        $found = collect($manifest['routes'])->firstWhere('name', 'products.index');

        $this->assertNotNull($found);
        $this->assertSame(['products.view'], $found['permissions']);
        $this->assertSame(100, $found['rate_limit']);
    }

    public function test_build_excludes_laravel_internal_action_keys(): void
    {
        Route::get('api/items', fn () => 'ok')->name('items.index');

        $manifest = $this->registry->build();

        $route = collect($manifest['routes'])->firstWhere('name', 'items.index');

        $this->assertNotNull($route);
        $this->assertArrayNotHasKey('uses', $route);
        $this->assertArrayNotHasKey('controller', $route);
        $this->assertArrayNotHasKey('middleware', $route);
        $this->assertArrayNotHasKey('as', $route);
    }

    public function test_build_adds_leading_slash_to_uri(): void
    {
        Route::get('api/products', fn () => 'ok');

        $manifest = $this->registry->build();

        $uris = array_column($manifest['routes'], 'uri');

        $this->assertContains('/api/products', $uris);
    }
}
