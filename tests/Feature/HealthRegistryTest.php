<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Redis\Connections\Connection;
use Jurager\Microservice\Registry\HealthRegistry;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class HealthRegistryTest extends TestCase
{
    private Connection $redis;

    private HealthRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = Mockery::mock(Connection::class);

        $this->registry = Mockery::mock(HealthRegistry::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $this->registry->shouldReceive('redis')->andReturn($this->redis);
    }

    public function test_get_instances_returns_configured_base_urls(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', [
            'http://oms-1:8000',
            'http://oms-2:8000',
        ]);

        $registry = new HealthRegistry();
        $instances = $registry->getInstances('oms');

        $this->assertSame(['http://oms-1:8000', 'http://oms-2:8000'], $instances);
    }

    public function test_get_instances_returns_empty_for_unknown_service(): void
    {
        $registry = new HealthRegistry();

        $this->assertSame([], $registry->getInstances('unknown'));
    }

    public function test_mark_failure_increments_counter(): void
    {
        $this->redis->shouldReceive('get')->once()->andReturn(null);
        $this->redis->shouldReceive('setex')->once()->withArgs(function ($key, $ttl, $value) {
            $data = json_decode($value, true);

            return $ttl === 60 && $data['failures'] === 1 && $data['last_failure'] > 0;
        });

        $this->registry->markFailure('oms', 'http://oms:8000');
    }

    public function test_mark_failure_increments_existing_counter(): void
    {
        $this->redis->shouldReceive('get')->once()->andReturn(
            json_encode(['failures' => 2, 'last_failure' => 1700000000])
        );
        $this->redis->shouldReceive('setex')->once()->withArgs(function ($key, $ttl, $value) {
            $data = json_decode($value, true);

            return $data['failures'] === 3;
        });

        $this->registry->markFailure('oms', 'http://oms:8000');
    }

    public function test_mark_success_deletes_health_key(): void
    {
        $this->redis->shouldReceive('del')->once();

        $this->registry->markSuccess('oms', 'http://oms:8000');
    }

    public function test_healthy_when_below_threshold(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);

        $this->redis->shouldReceive('get')->once()->andReturn(
            json_encode(['failures' => 2, 'last_failure' => time()])
        );

        $healthy = $this->registry->getHealthyInstances('oms');

        $this->assertSame(['http://oms:8000'], $healthy);
    }

    public function test_unhealthy_when_at_threshold(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);

        $this->redis->shouldReceive('get')->once()->andReturn(
            json_encode(['failures' => 3, 'last_failure' => time()])
        );

        $healthy = $this->registry->getHealthyInstances('oms');

        $this->assertEmpty($healthy);
    }

    public function test_recovers_after_timeout(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);

        $this->redis->shouldReceive('get')->once()->andReturn(
            json_encode(['failures' => 5, 'last_failure' => time() - 31])
        );

        $healthy = $this->registry->getHealthyInstances('oms');

        $this->assertSame(['http://oms:8000'], $healthy);
    }

    public function test_get_all_health_returns_data_for_all_services(): void
    {
        $this->app['config']->set('microservice.services', [
            'oms' => ['base_urls' => ['http://oms-1:8000', 'http://oms-2:8000']],
            'pim' => ['base_urls' => ['http://pim:8000']],
        ]);

        $this->redis->shouldReceive('get')
            ->andReturn(
                json_encode(['failures' => 2, 'last_failure' => time()]),
                null,
                json_encode(['failures' => 5, 'last_failure' => time() - 31]),
            );

        $health = $this->registry->getAllHealth();

        $this->assertArrayHasKey('oms', $health);
        $this->assertArrayHasKey('pim', $health);
        $this->assertCount(2, $health['oms']);
        $this->assertCount(1, $health['pim']);

        $this->assertSame(2, $health['oms'][0]['failures']);
        $this->assertTrue($health['oms'][0]['healthy']);

        $this->assertSame(0, $health['oms'][1]['failures']);
        $this->assertTrue($health['oms'][1]['healthy']);

        $this->assertSame(5, $health['pim'][0]['failures']);
        $this->assertTrue($health['pim'][0]['healthy']); // recovered after timeout
    }

    public function test_get_instance_health_returns_null_for_no_data(): void
    {
        $this->redis->shouldReceive('get')->once()->andReturn(null);

        $this->assertNull($this->registry->getInstanceHealth('oms', 'http://oms:8000'));
    }

    public function test_get_instance_health_returns_null_for_false(): void
    {
        $this->redis->shouldReceive('get')->once()->andReturn(false);

        $this->assertNull($this->registry->getInstanceHealth('oms', 'http://oms:8000'));
    }

    public function test_get_instance_health_returns_null_for_invalid_json(): void
    {
        $this->redis->shouldReceive('get')->once()->andReturn('not-json{{{');

        $this->assertNull($this->registry->getInstanceHealth('oms', 'http://oms:8000'));
    }

    public function test_get_instance_health_returns_data_for_valid_json(): void
    {
        $this->redis->shouldReceive('get')->once()->andReturn(
            json_encode(['failures' => 3, 'last_failure' => 1700000000])
        );

        $health = $this->registry->getInstanceHealth('oms', 'http://oms:8000');

        $this->assertSame(3, $health['failures']);
        $this->assertSame(1700000000, $health['last_failure']);
    }

    public function test_healthy_with_no_health_data(): void
    {
        $this->app['config']->set('microservice.services.oms.base_urls', ['http://oms:8000']);

        $this->redis->shouldReceive('get')->once()->andReturn(null);

        $healthy = $this->registry->getHealthyInstances('oms');

        $this->assertSame(['http://oms:8000'], $healthy);
    }
}
