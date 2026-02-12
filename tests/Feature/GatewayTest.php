<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Gateway\Gateway;
use Jurager\Microservice\Registry\RouteRegistry;
use Jurager\Microservice\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Mockery;

class GatewayTest extends TestCase
{
    private function mockManifests(array $manifests): void
    {
        $registry = Mockery::mock(RouteRegistry::class);
        $registry->shouldReceive('getAllManifests')->andReturn($manifests);
        $this->app->instance(RouteRegistry::class, $registry);
    }

    private function pimManifest(array $routes = []): array
    {
        return [
            'service' => 'pim',
            'routes' => $routes ?: [
                ['method' => 'GET', 'uri' => '/api/products', 'name' => 'products.index'],
                ['method' => 'POST', 'uri' => '/api/products', 'name' => 'products.store'],
            ],
        ];
    }

    private function findRouteByUri(string $uri, string $method = 'GET'): ?\Illuminate\Routing\Route
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }

    public function test_routes_registers_laravel_routes_from_manifests(): void
    {
        $this->mockManifests(['pim' => $this->pimManifest()]);

        Gateway::routes();

        $this->assertNotNull($this->findRouteByUri('pim/api/products', 'GET'));
        $this->assertNotNull($this->findRouteByUri('pim/api/products', 'POST'));
    }

    public function test_routes_filters_by_service_names(): void
    {
        $this->mockManifests([
            'pim' => $this->pimManifest(),
            'oms' => [
                'service' => 'oms',
                'routes' => [
                    ['method' => 'GET', 'uri' => '/api/orders', 'name' => 'orders.index'],
                ],
            ],
        ]);

        Gateway::routes(services: ['pim']);

        $this->assertNotNull($this->findRouteByUri('pim/api/products', 'GET'));
        $this->assertNull($this->findRouteByUri('oms/api/orders', 'GET'));
    }

    public function test_routes_applies_service_prefix(): void
    {
        $this->mockManifests(['pim' => $this->pimManifest()]);

        Gateway::routes(fn ($r) => $r->service('pim')->prefix('catalog'));

        $route = $this->findRouteByUri('catalog/api/products', 'GET');

        $this->assertNotNull($route);
    }

    public function test_routes_sets_metadata_on_route_action(): void
    {
        $this->mockManifests(['pim' => [
            'service' => 'pim',
            'routes' => [
                [
                    'method' => 'GET',
                    'uri' => '/api/products',
                    'name' => 'products.index',
                    'permissions' => ['products.view'],
                ],
            ],
        ]]);

        Gateway::routes();

        $route = $this->findRouteByUri('pim/api/products', 'GET');
        $this->assertNotNull($route);

        $action = $route->getAction();
        $this->assertSame('pim', $action['_service']);
        $this->assertSame('/api/products', $action['_service_uri']);
        $this->assertSame(['products.view'], $action['permissions']);
    }

    public function test_routes_applies_controller_override(): void
    {
        $this->mockManifests(['pim' => $this->pimManifest([
            ['method' => 'GET', 'uri' => '/api/products', 'name' => 'products.index'],
        ])]);

        Gateway::routes(fn ($r) => $r->service('pim')
            ->get('/api/products', [GatewayFakeController::class, 'index']));

        $route = $this->findRouteByUri('pim/api/products', 'GET');
        $this->assertNotNull($route);

        $this->assertStringContainsString('GatewayFakeController', $route->getAction('controller'));
    }

    public function test_routes_uses_custom_default_controller(): void
    {
        $this->mockManifests(['pim' => $this->pimManifest([
            ['method' => 'GET', 'uri' => '/api/products', 'name' => 'products.index'],
        ])]);

        Gateway::routes(controller: GatewayFakeController::class);

        $route = $this->findRouteByUri('pim/api/products', 'GET');
        $this->assertNotNull($route);

        $this->assertStringContainsString('GatewayFakeController', $route->getAction('controller'));
    }

    public function test_routes_registers_named_routes(): void
    {
        $this->mockManifests(['pim' => $this->pimManifest([
            ['method' => 'GET', 'uri' => '/api/products', 'name' => 'products.index'],
        ])]);

        Gateway::routes();

        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('products.index');

        $this->assertNotNull($route);
        $this->assertSame('pim/api/products', $route->uri());
    }

    public function test_routes_applies_combined_service_and_route_middleware(): void
    {
        $this->mockManifests(['pim' => $this->pimManifest([
            ['method' => 'GET', 'uri' => '/api/products', 'name' => 'products.index'],
            ['method' => 'POST', 'uri' => '/api/products', 'name' => 'products.store'],
        ])]);

        Gateway::routes(
            fn ($r) => $r
            ->service('pim')
            ->middleware(['auth:api'])
            ->post('/api/products')
            ->middleware(['throttle:10'])
        );

        $getRoute = $this->findRouteByUri('pim/api/products', 'GET');
        $postRoute = $this->findRouteByUri('pim/api/products', 'POST');

        $this->assertNotNull($getRoute);
        $this->assertNotNull($postRoute);

        $this->assertContains('auth:api', $getRoute->middleware());
        $this->assertNotContains('throttle:10', $getRoute->middleware());

        $this->assertContains('auth:api', $postRoute->middleware());
        $this->assertContains('throttle:10', $postRoute->middleware());
    }

    public function test_routes_skips_empty_name(): void
    {
        $this->mockManifests(['pim' => [
            'service' => 'pim',
            'routes' => [
                ['method' => 'GET', 'uri' => '/api/products'],
            ],
        ]]);

        Gateway::routes();

        $route = $this->findRouteByUri('pim/api/products', 'GET');

        $this->assertNotNull($route);
        $this->assertNull($route->getName());
    }

    public function test_routes_handles_prefix_with_single_segment_uri(): void
    {
        $this->mockManifests(['pim' => [
            'service' => 'pim',
            'routes' => [
                ['method' => 'GET', 'uri' => '/api', 'name' => 'api.root'],
            ],
        ]]);

        Gateway::routes(fn ($r) => $r->service('pim')->prefix('v2'));

        $route = $this->findRouteByUri('v2/api', 'GET');

        $this->assertNotNull($route);
    }
}

class GatewayFakeController
{
    public function index(): string
    {
        return 'ok';
    }
}
