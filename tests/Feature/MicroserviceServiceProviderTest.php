<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Registry\HealthRegistry;
use Jurager\Microservice\Support\HmacSigner;
use Jurager\Microservice\Tests\TestCase;

class MicroserviceServiceProviderTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertNotNull(config('microservice.name'));
        $this->assertNotNull(config('microservice.health'));
        $this->assertIsArray(config('microservice.services'));
    }

    public function test_health_registry_is_singleton(): void
    {
        $a = $this->app->make(HealthRegistry::class);
        $b = $this->app->make(HealthRegistry::class);

        $this->assertSame($a, $b);
    }

    public function test_hmac_signer_is_singleton(): void
    {
        $a = $this->app->make(HmacSigner::class);
        $b = $this->app->make(HmacSigner::class);

        $this->assertSame($a, $b);
    }

    public function test_service_client_is_singleton(): void
    {
        $a = $this->app->make(ServiceClient::class);
        $b = $this->app->make(ServiceClient::class);

        $this->assertSame($a, $b);
    }

    public function test_commands_are_registered(): void
    {
        $this->artisan('list')
            ->assertSuccessful();

        $commands = array_keys(\Illuminate\Support\Facades\Artisan::all());

        $this->assertContains('microservice:register', $commands);
        $this->assertContains('microservice:health', $commands);
    }

    public function test_manifest_route_is_registered(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes());

        $manifestRoute = $routes->first(function ($route) {
            return $route->uri() === 'microservice/manifest' && in_array('POST', $route->methods());
        });

        $this->assertNotNull($manifestRoute);
    }
}
