<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Cache\Repository as Cache;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class HealthControllerTest extends TestCase
{
    private Cache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the bus out of health checks so we never touch a real broker.
        config([
            'microservice.bus.enabled' => false,
            'microservice.manifest.services' => ['pim'],
            'microservice.manifest.sync_interval' => 5,
            'microservice.circuit_breaker.threshold' => 0,
            'microservice.health.cache_ttl' => 0,
        ]);

        $this->cache = Mockery::mock(Cache::class);
        $this->app->instance(Cache::class, $this->cache);
    }

    private function manifest(string $syncedAt, int $routes = 3): string
    {
        return json_encode([
            'service' => 'pim',
            'base_url' => 'https://pim.test',
            'timeout' => 30,
            'synced_at' => $syncedAt,
            'routes' => array_fill(0, $routes, ['method' => 'GET', 'uri' => '/api/x']),
        ]);
    }

    public function test_reports_healthy_when_no_services_are_configured(): void
    {
        // The default for every service that isn't the gateway.
        config(['microservice.manifest.services' => []]);

        $this->cache->shouldReceive('has')->with('microservice:ping')->andReturnTrue();

        $this->getJson('/microservice/health')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('summary.total', 0)
            ->assertJsonPath('services', []);
    }

    public function test_readiness_passes_when_no_services_are_configured(): void
    {
        config(['microservice.manifest.services' => []]);

        $this->cache->shouldReceive('has')->with('microservice:ping')->andReturnTrue();

        $this->getJson('/microservice/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('manifests_loaded', 0);
    }

    public function test_ignores_a_non_string_service_filter(): void
    {
        $this->cache->shouldReceive('has')->with('microservice:ping')->andReturnTrue();
        $this->cache->shouldReceive('get')
            ->with('microservice:manifest:pim')
            ->andReturn($this->manifest(now()->toIso8601String()));

        $this->getJson('/microservice/health?service[]=pim')
            ->assertOk()
            ->assertJsonPath('summary.total', 1);
    }

    public function test_reports_healthy_for_a_fresh_manifest(): void
    {
        $this->cache->shouldReceive('has')->with('microservice:ping')->andReturnTrue();
        $this->cache->shouldReceive('get')
            ->with('microservice:manifest:pim')
            ->andReturn($this->manifest(now()->toIso8601String()));

        $this->getJson('/microservice/health')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('gateway', 'test-service')
            ->assertJsonPath('summary.healthy', 1)
            ->assertJsonPath('services.pim.status', 'healthy')
            ->assertJsonPath('services.pim.routes_count', 3)
            ->assertJsonPath('dependencies.cache.status', 'up')
            ->assertJsonStructure(['status', 'instance', 'checked_at', 'summary', 'services']);
    }

    public function test_hides_infrastructure_config_unless_verbose(): void
    {
        $this->cache->shouldReceive('has')->with('microservice:ping')->andReturnTrue();
        $this->cache->shouldReceive('get')->andReturn($this->manifest(now()->toIso8601String()));

        $this->getJson('/microservice/health')
            ->assertOk()
            ->assertJsonMissingPath('services.pim.base_url');

        $this->getJson('/microservice/health?verbose=1')
            ->assertOk()
            ->assertJsonPath('services.pim.base_url', 'https://pim.test')
            ->assertJsonPath('services.pim.timeout', 30);
    }


    public function test_returns_503_when_a_manifest_is_missing(): void
    {
        $this->cache->shouldReceive('has')->with('microservice:ping')->andReturnTrue();
        $this->cache->shouldReceive('get')->andReturn(null);

        $this->getJson('/microservice/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('services.pim.status', 'missing');
    }

    public function test_returns_503_when_redis_is_down(): void
    {
        $this->cache->shouldReceive('has')->with('microservice:ping')->andThrow(new \RuntimeException('connection refused'));
        $this->cache->shouldReceive('get')->andReturn(null);

        $this->getJson('/microservice/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('dependencies.cache.status', 'down');
    }

    public function test_liveness_is_always_ok_without_touching_redis(): void
    {
        $this->getJson('/microservice/health/live')
            ->assertOk()
            ->assertJsonPath('status', 'healthy');
    }

    public function test_readiness_is_ok_when_redis_up_and_manifest_present(): void
    {
        $this->cache->shouldReceive('has')->with('microservice:ping')->andReturnTrue();
        $this->cache->shouldReceive('get')->andReturn($this->manifest(now()->toIso8601String()));

        $this->getJson('/microservice/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('manifests_loaded', 1);
    }

    public function test_readiness_is_503_when_manifest_missing(): void
    {
        $this->cache->shouldReceive('has')->with('microservice:ping')->andReturnTrue();
        $this->cache->shouldReceive('get')->andReturn(null);

        $this->getJson('/microservice/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy');
    }

    public function test_exposes_circuit_breaker_state(): void
    {
        config(['microservice.circuit_breaker.threshold' => 5, 'microservice.circuit_breaker.timeout' => 30]);

        $this->cache->shouldReceive('has')->with('microservice:ping')->andReturnTrue();
        $this->cache->shouldReceive('get')
            ->with('microservice:manifest:pim')
            ->andReturn($this->manifest(now()->toIso8601String()));
        $this->cache->shouldReceive('get')->with('microservice:circuit:pim')->andReturn('open');
        $this->cache->shouldReceive('get')->with('microservice:circuit:pim:failures')->andReturn('7');
        $this->cache->shouldReceive('get')->with('microservice:circuit:pim:opened')->andReturn((string) now()->timestamp);

        $this->getJson('/microservice/health')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('services.pim.circuit.state', 'open')
            ->assertJsonPath('services.pim.circuit.failures', 7);
    }

    public function test_metrics_endpoint_exposes_prometheus_text(): void
    {
        $this->cache->shouldReceive('has')->with('microservice:ping')->andReturnTrue();
        $this->cache->shouldReceive('get')->andReturn($this->manifest(now()->toIso8601String()));

        $response = $this->get('/microservice/metrics');

        $response->assertOk();
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));

        $body = $response->getContent();
        $this->assertStringContainsString('microservice_up 1', $body);
        $this->assertStringContainsString('microservice_service_up{service="pim"} 1', $body);
        $this->assertStringContainsString('microservice_service_routes_count{service="pim"} 3', $body);
        $this->assertStringContainsString('# TYPE microservice_up gauge', $body);
    }
}
