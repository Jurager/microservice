<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Jurager\Microservice\Client\CircuitBreaker;
use Jurager\Microservice\Rules\ServiceExists;
use Jurager\Microservice\Tests\TestCase;

class CacheStoreTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A shared store named after nothing the old driver whitelist knew.
        $app['config']->set('cache.stores.shared', ['driver' => 'array']);
        $app['config']->set('microservice.cache.store', 'shared');
        $app['config']->set('microservice.circuit_breaker.threshold', 1);
    }

    public function test_components_use_the_configured_store(): void
    {
        $this->app->make(CircuitBreaker::class)->recordFailure('oms');

        $this->assertNotNull(Cache::store('shared')->get('microservice:circuit:oms:opened'));
        $this->assertNull(Cache::store('array')->get('microservice:circuit:oms:opened'));
    }

    public function test_service_exists_reports_the_package_translation(): void
    {
        $validator = Validator::make(['source' => 'ghost'], ['source' => [new ServiceExists]]);

        $this->assertSame('The source service was not found.', $validator->errors()->first('source'));
    }
}
