<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Jurager\Microservice\Tests\TestCase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class TrustProxiesConfigTest extends TestCase
{
    protected function setUp(): void
    {
        TrustProxies::flushState();
        SymfonyRequest::setTrustedProxies([], 0);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        SymfonyRequest::setTrustedProxies([], 0);

        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        $router->get('/test/url-check', function (Request $request) {
            return response()->json([
                'host' => $request->getHost(),
                'scheme' => $request->getScheme(),
                'base_url' => $request->getBaseUrl(),
            ]);
        });
    }

    public function test_trusts_proxies_when_gateway_is_configured(): void
    {
        config()->set('microservice.manifest.gateway', 'gateway');

        $this->app->getProvider(\Jurager\Microservice\MicroserviceServiceProvider::class)->boot();

        $response = $this->get('/test/url-check', [
            'X-Forwarded-Host' => 'api.example.com',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Prefix' => '/pim',
        ]);

        $response->assertOk();
        $this->assertSame('api.example.com', $response->json('host'));
        $this->assertSame('https', $response->json('scheme'));
        $this->assertSame('/pim', $response->json('base_url'));
    }

    public function test_does_not_configure_proxies_when_no_gateway(): void
    {
        config()->set('microservice.manifest.gateway', null);

        $this->app->getProvider(\Jurager\Microservice\MicroserviceServiceProvider::class)->boot();

        $response = $this->get('/test/url-check', [
            'X-Forwarded-Host' => 'api.example.com',
        ]);

        $response->assertOk();
        $this->assertNotSame('api.example.com', $response->json('host'));
    }
}
