<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Registry\HealthRegistry;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class ServiceHealthCommandTest extends TestCase
{
    public function test_displays_health_table(): void
    {
        $registry = Mockery::mock(HealthRegistry::class);
        $registry->shouldReceive('getAllHealth')->andReturn([
            'oms' => [
                [
                    'url' => 'http://oms-1:8000',
                    'failures' => 0,
                    'last_failure' => null,
                    'healthy' => true,
                ],
                [
                    'url' => 'http://oms-2:8000',
                    'failures' => 5,
                    'last_failure' => time(),
                    'healthy' => false,
                ],
            ],
        ]);

        $this->app->instance(HealthRegistry::class, $registry);

        $this->artisan('service:health')
            ->assertSuccessful();
    }

    public function test_warns_when_no_services_configured(): void
    {
        $registry = Mockery::mock(HealthRegistry::class);
        $registry->shouldReceive('getAllHealth')->andReturn([]);

        $this->app->instance(HealthRegistry::class, $registry);

        $this->artisan('service:health')
            ->assertSuccessful();
    }
}
