<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class HealthControllerTest extends TestCase
{
    private Connection $redis;

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

        $this->redis = Mockery::mock(Connection::class);
        Redis::shouldReceive('connection')->andReturn($this->redis);
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

    public function test_reports_healthy_for_a_fresh_manifest(): void
    {
        $this->redis->shouldReceive('ping')->andReturnTrue();
        $this->redis->shouldReceive('get')
            ->with('microservice:test:manifest:pim')
            ->andReturn($this->manifest(now()->toIso8601String()));
        $this->redis->shouldReceive('ttl')
            ->with('microservice:test:manifest:pim')
            ->andReturn(250);

        $this->getJson('/microservice/health')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('gateway', 'test-service')
            ->assertJsonPath('summary.healthy', 1)
            ->assertJsonPath('services.pim.status', 'healthy')
            ->assertJsonPath('services.pim.routes_count', 3)
            ->assertJsonPath('dependencies.redis.status', 'up')
            ->assertJsonStructure(['status', 'instance', 'checked_at', 'summary', 'services']);
    }

    public function test_hides_infrastructure_config_unless_verbose(): void
    {
        $this->redis->shouldReceive('ping')->andReturnTrue();
        $this->redis->shouldReceive('get')->andReturn($this->manifest(now()->toIso8601String()));
        $this->redis->shouldReceive('ttl')->andReturn(250);

        $this->getJson('/microservice/health')
            ->assertOk()
            ->assertJsonMissingPath('services.pim.base_url');

        $this->getJson('/microservice/health?verbose=1')
            ->assertOk()
            ->assertJsonPath('services.pim.base_url', 'https://pim.test')
            ->assertJsonPath('services.pim.timeout', 30);
    }

    public function test_marks_old_manifest_as_stale_and_degraded(): void
    {
        // sync_interval 5m * stale_factor 1.5 = 450s threshold; 600s is stale.
        $this->redis->shouldReceive('ping')->andReturnTrue();
        $this->redis->shouldReceive('get')->andReturn($this->manifest(now()->subSeconds(600)->toIso8601String()));
        $this->redis->shouldReceive('ttl')->andReturn(120);

        $this->getJson('/microservice/health')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('services.pim.status', 'stale')
            ->assertJsonPath('services.pim.reason', 'manifest_stale');
    }

    public function test_returns_503_when_a_manifest_is_missing(): void
    {
        $this->redis->shouldReceive('ping')->andReturnTrue();
        $this->redis->shouldReceive('get')->andReturn(null);

        $this->getJson('/microservice/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('services.pim.status', 'missing')
            ->assertJsonPath('services.pim.reason', 'manifest_missing');
    }

    public function test_returns_503_when_redis_is_down(): void
    {
        $this->redis->shouldReceive('ping')->andThrow(new \RuntimeException('connection refused'));
        $this->redis->shouldReceive('get')->andReturn(null);

        $this->getJson('/microservice/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('dependencies.redis.status', 'down');
    }

    public function test_liveness_is_always_ok_without_touching_redis(): void
    {
        $this->getJson('/microservice/health/live')
            ->assertOk()
            ->assertJsonPath('status', 'healthy');
    }

    public function test_readiness_is_ok_when_redis_up_and_manifest_present(): void
    {
        $this->redis->shouldReceive('ping')->andReturnTrue();
        $this->redis->shouldReceive('get')->andReturn($this->manifest(now()->toIso8601String()));
        $this->redis->shouldReceive('ttl')->andReturn(250);

        $this->getJson('/microservice/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('manifests_loaded', 1);
    }

    public function test_readiness_is_503_when_manifest_missing(): void
    {
        $this->redis->shouldReceive('ping')->andReturnTrue();
        $this->redis->shouldReceive('get')->andReturn(null);

        $this->getJson('/microservice/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy');
    }

    public function test_exposes_circuit_breaker_state(): void
    {
        config(['microservice.circuit_breaker.threshold' => 5, 'microservice.circuit_breaker.timeout' => 30]);

        $this->redis->shouldReceive('ping')->andReturnTrue();
        $this->redis->shouldReceive('get')
            ->with('microservice:test:manifest:pim')
            ->andReturn($this->manifest(now()->toIso8601String()));
        $this->redis->shouldReceive('ttl')->andReturn(250);
        $this->redis->shouldReceive('get')->with('microservice:test:circuit:pim')->andReturn('open');
        $this->redis->shouldReceive('get')->with('microservice:test:circuit:pim:failures')->andReturn('7');
        $this->redis->shouldReceive('get')->with('microservice:test:circuit:pim:opened')->andReturn((string) now()->timestamp);

        $this->getJson('/microservice/health')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('services.pim.circuit.state', 'open')
            ->assertJsonPath('services.pim.circuit.failures', 7);
    }

    public function test_metrics_endpoint_exposes_prometheus_text(): void
    {
        $this->redis->shouldReceive('ping')->andReturnTrue();
        $this->redis->shouldReceive('get')->andReturn($this->manifest(now()->toIso8601String()));
        $this->redis->shouldReceive('ttl')->andReturn(250);

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
