<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Client\ServiceDiscovery;
use Jurager\Microservice\Http\Middleware\LogContext;
use Jurager\Microservice\MicroserviceServiceProvider;
use Jurager\Microservice\Signing\Signer;
use Jurager\Microservice\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MicroserviceServiceProviderTest extends TestCase
{
    public function test_the_application_middleware_is_left_to_the_application(): void
    {
        $this->assertNotContains(LogContext::class, $this->app['router']->getMiddlewareGroups()['api'] ?? []);
        $this->assertNull((fn () => static::$alwaysTrustProxies ?? null)->call(new \Illuminate\Http\Middleware\TrustProxies));
    }

    public function test_config_is_merged(): void
    {
        $this->assertNotNull(config('microservice.name'));
        $this->assertNotNull(config('microservice.health'));
        $this->assertIsArray(config('microservice.manifest.services'));
    }

    #[DataProvider('unsetServices')]
    public function test_unset_manifest_services_are_an_empty_list(?string $services): void
    {
        $this->app['config']->set('microservice.manifest.services', $services);

        $this->app->register(MicroserviceServiceProvider::class, force: true);

        $this->assertSame([], config('microservice.manifest.services'));
        $this->assertSame([], $this->app->make(ServiceDiscovery::class)->services());
    }

    public static function unsetServices(): array
    {
        return [
            'empty env' => [''],
            'null' => [null],
        ];
    }

    public function test_manifest_services_are_read_from_a_comma_separated_list(): void
    {
        $this->app['config']->set('microservice.manifest.services', ' pim, oms ,,');

        $this->app->register(MicroserviceServiceProvider::class, force: true);

        $this->assertSame(['pim', 'oms'], config('microservice.manifest.services'));
    }

    public function test_signer_is_singleton(): void
    {
        $a = $this->app->make(Signer::class);
        $b = $this->app->make(Signer::class);

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
        $this->assertContains('microservice:keygen', $commands);
        $this->assertContains('microservice:authority:generate', $commands);
        $this->assertContains('microservice:certificate:issue', $commands);
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
