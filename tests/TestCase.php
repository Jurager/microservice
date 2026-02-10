<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests;

use Jurager\Microservice\MicroserviceServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            MicroserviceServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('microservice.name', 'test-service');
        $app['config']->set('microservice.secret', 'test-secret-key');
        $app['config']->set('microservice.algorithm', 'sha256');
        $app['config']->set('microservice.timestamp_tolerance', 60);
        $app['config']->set('microservice.redis.connection', 'default');
        $app['config']->set('microservice.redis.prefix', 'microservice:test:');
        $app['config']->set('microservice.health.failure_threshold', 3);
        $app['config']->set('microservice.health.recovery_timeout', 30);
        $app['config']->set('microservice.manifest.ttl', 300);
        $app['config']->set('microservice.manifest.prefix', 'api');
        $app['config']->set('microservice.manifest.gateway', null);
        $app['config']->set('microservice.idempotency.ttl', 60);
        $app['config']->set('microservice.idempotency.lock_timeout', 10);
        $app['config']->set('microservice.defaults.timeout', 5);
        $app['config']->set('microservice.defaults.retries', 2);
        $app['config']->set('microservice.defaults.retry_delay', 0);
        $app['config']->set('microservice.services', []);
    }
}
