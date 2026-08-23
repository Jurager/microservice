<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests;

use Illuminate\Support\Facades\Cache;
use Jurager\Microservice\MicroserviceServiceProvider;
use Jurager\Microservice\Support\Ecdsa;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /** Base64 private key for 'test-service'. */
    protected static string $privateKey;

    /** Base64 public key for 'test-service' — the counterpart of $privateKey. */
    protected static string $publicKey;

    protected function getPackageProviders($app): array
    {
        return [
            MicroserviceServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $pair = self::generateKeyPair();
        static::$privateKey = $pair['private'];
        static::$publicKey = $pair['public'];

        $app['config']->set('microservice.name', 'test-service');
        $app['config']->set('microservice.signing.private_key', static::$privateKey);
        $app['config']->set('microservice.timestamp_tolerance', 60);
        $app['config']->set('microservice.redis.connection', 'default');
        $app['config']->set('microservice.redis.prefix', 'microservice:test:');
        $app['config']->set('microservice.manifest.ttl', 300);
        $app['config']->set('microservice.manifest.prefix', 'api');
        $app['config']->set('microservice.manifest.services', []);
        $app['config']->set('microservice.idempotency.ttl', 60);
        $app['config']->set('microservice.idempotency.lock_timeout', 10);
        $app['config']->set('microservice.bus.enabled', true);
        $app['config']->set('microservice.bus.exchange', 'events');
        $app['config']->set('microservice.bus.dead_letter.enabled', true);
        $app['config']->set('microservice.bus.dead_letter.exchange', 'events.dlx');
    }

    /**
     * Put a peer's public key where PublicKeyResolver looks first — its cached
     * manifest — so verifying its signature doesn't require a live HTTP fetch.
     */
    protected function trustPeer(string $service, string $publicKey): void
    {
        Cache::put("microservice:manifest:$service", [
            'service' => $service,
            'base_url' => "https://$service.test",
            'public_key' => $publicKey,
        ]);
    }

    /** @return array{private: string, public: string} Base64-wrapped EC key pair. */
    protected static function generateKeyPair(): array
    {
        return Ecdsa::generateKeyPair();
    }
}
