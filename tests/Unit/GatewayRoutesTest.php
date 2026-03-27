<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\Gateway\GatewayRoutes;
use PHPUnit\Framework\TestCase;

class GatewayRoutesTest extends TestCase
{
    private GatewayRoutes $routes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->routes = new GatewayRoutes();
    }

    public function test_prefix_sets_service_prefix(): void
    {
        $this->routes->service('pim')->prefix('catalog');

        $this->assertSame(['pim' => 'catalog'], $this->routes->getServicePrefixes());
    }

    public function test_middleware_without_route_sets_service_middleware(): void
    {
        $this->routes->service('pim')->middleware(['auth']);

        $this->assertSame(['pim' => ['auth']], $this->routes->getServiceMiddleware());
    }

    public function test_middleware_after_route_sets_route_middleware(): void
    {
        $this->routes->service('oms')->post('/api/orders')->middleware(['audit']);

        $this->assertSame(
            ['oms' => ['POST /api/orders' => ['audit']]],
            $this->routes->getRouteMiddleware()
        );
    }

    public function test_route_with_action_sets_override(): void
    {
        $action = ['FakeController', 'index'];
        $this->routes->service('pim')->get('/api/products', $action);

        $overrides = $this->routes->getOverrides();

        $this->assertSame($action, $overrides['pim']['GET /api/products']);
    }

    public function test_route_without_action_does_not_set_override(): void
    {
        $this->routes->service('oms')->post('/api/orders');

        $this->assertEmpty($this->routes->getOverrides());
    }

    public function test_uri_is_normalized_with_leading_slash(): void
    {
        $this->routes->service('pim')->get('api/products', ['FakeController', 'index']);

        $this->assertArrayHasKey('GET /api/products', $this->routes->getOverrides()['pim']);
    }

    public function test_multiple_services_tracked_independently(): void
    {
        $this->routes->service('pim')->prefix('catalog')->middleware(['analytics']);
        $this->routes->service('oms')->prefix('orders');

        $this->assertSame(['pim' => 'catalog', 'oms' => 'orders'], $this->routes->getServicePrefixes());
        $this->assertSame(['pim' => ['analytics']], $this->routes->getServiceMiddleware());
    }

    public function test_method_chaining_is_fluent(): void
    {
        $result = $this->routes->service('pim');
        $this->assertSame($this->routes, $result);

        $result = $this->routes->prefix('catalog');
        $this->assertSame($this->routes, $result);

        $result = $this->routes->middleware(['auth']);
        $this->assertSame($this->routes, $result);
    }

    public function test_put_sets_override(): void
    {
        $action = ['FakeController', 'update'];
        $this->routes->service('pim')->put('/api/products/{id}', $action);

        $overrides = $this->routes->getOverrides();

        $this->assertSame($action, $overrides['pim']['PUT /api/products/{id}']);
    }

    public function test_patch_sets_override(): void
    {
        $action = ['FakeController', 'patch'];
        $this->routes->service('oms')->patch('/api/orders/{id}', $action);

        $overrides = $this->routes->getOverrides();

        $this->assertSame($action, $overrides['oms']['PATCH /api/orders/{id}']);
    }

    public function test_delete_sets_override(): void
    {
        $action = ['FakeController', 'destroy'];
        $this->routes->service('oms')->delete('/api/orders/{id}', $action);

        $overrides = $this->routes->getOverrides();

        $this->assertSame($action, $overrides['oms']['DELETE /api/orders/{id}']);
    }

    public function test_route_without_action_sets_last_route_key(): void
    {
        $this->routes->service('pim')
            ->put('/api/products/{id}')
            ->middleware(['validate']);

        $routeMiddleware = $this->routes->getRouteMiddleware();

        $this->assertSame(
            ['pim' => ['PUT /api/products/{id}' => ['validate']]],
            $routeMiddleware
        );
        $this->assertEmpty($this->routes->getOverrides());
    }
}
