<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Event;
use Jurager\Microservice\Events\RoutesRegistered;
use Jurager\Microservice\Http\Middleware\TrustService;
use Jurager\Microservice\Tests\TestCase;

class ManifestControllerTest extends TestCase
{
    public function test_returns_manifest_with_routes(): void
    {
        $this->withoutMiddleware(TrustService::class)
            ->getJson('/microservice/manifest')
            ->assertOk()
            ->assertJsonStructure(['service', 'routes', 'timestamp', 'base_url']);
    }

    public function test_returns_correct_service_name(): void
    {
        $this->app['config']->set('microservice.name', 'pim');

        $this->withoutMiddleware(TrustService::class)
            ->getJson('/microservice/manifest')
            ->assertOk()
            ->assertJsonPath('service', 'pim');
    }

    public function test_dispatches_routes_registered_event(): void
    {
        Event::fake([RoutesRegistered::class]);

        $this->withoutMiddleware(TrustService::class)
            ->getJson('/microservice/manifest')
            ->assertOk();

        Event::assertDispatched(RoutesRegistered::class);
    }

    public function test_requires_trust_service_middleware(): void
    {
        $this->app['config']->set('microservice.debug', false);

        $this->getJson('/microservice/manifest')
            ->assertStatus(401);
    }
}
