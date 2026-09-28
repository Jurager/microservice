<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository as Cache;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Registry\ManifestRegistry;
use Jurager\Microservice\Support\Signer;
use Jurager\Microservice\Tests\TestCase;

class SyncPeersTest extends TestCase
{
    public function test_sync_pulls_peers_from_their_own_url_alongside_services(): void
    {
        $history = [];
        $manifest = static fn (string $service) => new Response(200, [], json_encode([
            'service' => $service,
            'base_url' => "https://$service.reported.by.peer",
            'routes' => [['methods' => ['GET'], 'uri' => '/v1/secure/attributes', 'name' => 'secure_attributes.index']],
        ]));

        $stack = HandlerStack::create(new MockHandler([$manifest('pim'), $manifest('api')]));
        $stack->push(Middleware::history($history));

        $this->app['config']->set('microservice.manifest.services', ['pim']);
        $this->app['config']->set('microservice.peers', ['api' => 'https://api.example.com']);
        $this->app['config']->set('microservice.discovery.pattern', 'http://{service}.svc');
        $this->app['config']->set('microservice.debug', true);

        $this->app->instance(ServiceClient::class, new ServiceClient(
            $this->app->make(Signer::class),
            $this->app->make(Cache::class),
            new Client(['handler' => $stack]),
        ));

        $this->artisan('microservice:sync')->assertSuccessful();

        $this->assertSame('http://pim.svc/microservice/manifest', (string) $history[0]['request']->getUri());
        $this->assertSame('https://api.example.com/microservice/manifest', (string) $history[1]['request']->getUri());

        $registry = $this->app->make(ManifestRegistry::class);
        $this->assertNotNull($registry->get('pim'));
        $this->assertSame('api', $registry->get('api')['service']);
    }
}
