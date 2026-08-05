<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Http\Middleware\LogContext;
use Jurager\Microservice\Support\HmacSigner;
use Jurager\Microservice\Tests\TestCase;

class MicroserviceServiceProviderTest extends TestCase
{
    /**
     * The provider prepends LogContext during boot, which only takes effect on
     * a group that already exists at that point - define it before the app boots,
     * the same way a consuming app's withRouting(api: ...) would.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['router']->middlewareGroup('api', []);
    }

    public function test_log_context_is_prepended_to_api_group(): void
    {
        $group = $this->app->make(Router::class)->getMiddlewareGroups()['api'];

        $this->assertSame(LogContext::class, $group[0] ?? null);
    }

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
