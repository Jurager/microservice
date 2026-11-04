<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jurager\Microservice\Manifest\ManifestRepository;
use Jurager\Microservice\Tests\TestCase;

class SyncPeersTest extends TestCase
{
    public function test_sync_pulls_peers_from_their_own_url_alongside_services(): void
    {
        $manifest = static fn (string $service) => Http::response([
            'service' => $service,
            'base_url' => "https://$service.reported.by.peer",
            'routes' => [['methods' => ['GET'], 'uri' => '/v1/secure/attributes', 'name' => 'secure_attributes.index']],
        ]);

        Http::fake([
            'pim.svc/*' => $manifest('pim'),
            'api.example.com/*' => $manifest('api'),
        ]);

        $this->app['config']->set('microservice.manifest.services', ['pim']);
        $this->app['config']->set('microservice.peers', ['api' => 'https://api.example.com']);
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}.svc');

        $this->artisan('microservice:sync')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'http://pim.svc/microservice/manifest');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.example.com/microservice/manifest');

        $registry = $this->app->make(ManifestRepository::class);
        $this->assertNotNull($registry->get('pim'));
        $this->assertSame('api', $registry->get('api')['service']);
    }
}
