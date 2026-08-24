<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Jurager\Microservice\Events\RoutesRegistered;
use Jurager\Microservice\Support\Signer;
use Jurager\Microservice\Tests\TestCase;

class ManifestControllerTest extends TestCase
{
    public function test_returns_manifest_with_routes(): void
    {
        $this->signedRequest()
            ->assertOk()
            ->assertJsonStructure(['service', 'routes', 'timestamp', 'base_url']);
    }

    public function test_returns_correct_service_name(): void
    {
        $this->app['config']->set('microservice.name', 'pim');

        $this->signedRequest()
            ->assertOk()
            ->assertJsonPath('service', 'pim');
    }

    public function test_dispatches_routes_registered_event(): void
    {
        Event::fake([RoutesRegistered::class]);

        $this->signedRequest()->assertOk();

        Event::assertDispatched(RoutesRegistered::class);
    }

    public function test_requires_a_signed_request(): void
    {
        $this->app['config']->set('microservice.debug', false);

        $this->get('/microservice/manifest')
            ->assertStatus(401);
    }

    private function signedRequest(): TestResponse
    {
        $signer = $this->app->make(Signer::class);
        $timestamp = (string) time();

        return $this->get('/microservice/manifest', [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signer->sign('GET', '/microservice/manifest', $timestamp),
            'X-Service-Name' => 'test-service',
            'X-Service-Cert' => $signer->certificate(),
        ]);
    }
}
