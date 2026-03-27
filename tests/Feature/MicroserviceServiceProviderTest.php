<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Support\HmacSigner;
use Jurager\Microservice\Tests\TestCase;

class MicroserviceServiceProviderTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertNotNull(config('microservice.name'));
        $this->assertNotNull(config('microservice.health'));
        $this->assertIsArray(config('microservice.manifest.services'));
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
        $this->artisan('list')->assertSuccessful();

        $commands = array_keys(Artisan::all());

        $this->assertContains('microservice:sync', $commands);
    }

    public function test_manifest_route_is_registered(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $manifestRoute = $routes->first(function ($route) {
            return $route->uri() === 'microservice/manifest' && in_array('GET', $route->methods());
        });

        $this->assertNotNull($manifestRoute);
    }
}
