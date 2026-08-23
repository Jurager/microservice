<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Jurager\Microservice\Registry\ManifestRegistry;
use Jurager\Microservice\Support\PublicKeyResolver;
use Jurager\Microservice\Tests\TestCase;

class PublicKeyResolverTest extends TestCase
{
    public function test_resolve_returns_key_from_a_cached_manifest(): void
    {
        $oms = self::generateKeyPair();
        $this->trustPeer('oms', $oms['public']);

        $resolver = $this->app->make(PublicKeyResolver::class);

        $this->assertSame($oms['public'], $resolver->resolve('oms'));
    }

    public function test_resolve_returns_null_without_a_cached_manifest_or_discovery_pattern(): void
    {
        $resolver = $this->makeResolver();

        $this->assertNull($resolver->resolve('never-seen'));
    }

    public function test_resolve_fetches_and_caches_a_live_manifest(): void
    {
        config(['microservice.discovery.pattern' => 'https://{service}.test']);

        $oms = self::generateKeyPair();
        $resolver = $this->makeResolver([
            new Response(200, [], json_encode([
                'service' => 'oms',
                'base_url' => 'https://oms.test',
                'public_key' => $oms['public'],
                'routes' => [],
            ])),
        ]);

        $this->assertSame($oms['public'], $resolver->resolve('oms'));

        // Fetched manifest is cached, so a second resolve doesn't need the (single-shot) mock again.
        $this->assertSame($oms['public'], $this->app->make(ManifestRegistry::class)->get('oms')['public_key']);
    }

    public function test_resolve_returns_null_when_the_response_has_no_public_key(): void
    {
        config(['microservice.discovery.pattern' => 'https://{service}.test']);

        $resolver = $this->makeResolver([
            new Response(200, [], json_encode(['service' => 'oms', 'routes' => []])),
        ]);

        $this->assertNull($resolver->resolve('oms'));
    }

    public function test_resolve_returns_null_on_malformed_json(): void
    {
        config(['microservice.discovery.pattern' => 'https://{service}.test']);

        $resolver = $this->makeResolver([
            new Response(200, [], 'not json'),
        ]);

        $this->assertNull($resolver->resolve('oms'));
    }

    public function test_resolve_force_bypasses_a_cached_key(): void
    {
        config(['microservice.discovery.pattern' => 'https://{service}.test']);

        $stale = self::generateKeyPair();
        $this->trustPeer('oms', $stale['public']);

        $rotated = self::generateKeyPair();
        $resolver = $this->makeResolver([
            new Response(200, [], json_encode([
                'service' => 'oms',
                'base_url' => 'https://oms.test',
                'public_key' => $rotated['public'],
                'routes' => [],
            ])),
        ]);

        $this->assertSame($stale['public'], $resolver->resolve('oms'));
        $this->assertSame($rotated['public'], $resolver->resolve('oms', force: true));
    }

    private function makeResolver(array $responses = []): PublicKeyResolver
    {
        $handler = HandlerStack::create(new MockHandler($responses));

        return new PublicKeyResolver(
            $this->app->make(ManifestRegistry::class),
            new Client(['handler' => $handler]),
        );
    }
}
