<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Jurager\Microservice\Events\RoutesRegistered;
use Jurager\Microservice\Tests\TestCase;

class ManifestControllerTest extends TestCase
{
    public function test_returns_manifest_with_routes(): void
    {
        $this->getJson('/microservice/manifest')
            ->assertOk()
            ->assertJsonStructure(['service', 'routes', 'timestamp', 'base_url', 'public_key']);
    }

    public function test_returns_correct_service_name(): void
    {
        $this->app['config']->set('microservice.name', 'pim');

        $this->getJson('/microservice/manifest')
            ->assertOk()
            ->assertJsonPath('service', 'pim');
    }

    public function test_returns_this_service_own_public_key(): void
    {
        $this->getJson('/microservice/manifest')
            ->assertOk()
            ->assertJsonPath('public_key', static::$publicKey);
    }

    public function test_dispatches_routes_registered_event(): void
    {
        Event::fake([RoutesRegistered::class]);

        $this->getJson('/microservice/manifest')
            ->assertOk();

        Event::assertDispatched(RoutesRegistered::class);
    }

    public function test_is_public_and_unsigned(): void
    {
        // No X-Signature/X-Timestamp/X-Service-Name headers at all — this is
        // how a peer's public key reaches the rest of the cluster in the
        // first place, so it can't itself require a signature to read.
        $this->app['config']->set('microservice.debug', false);

        $this->getJson('/microservice/manifest')
            ->assertOk();
    }
}
