<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests;

use Jurager\Microservice\MicroserviceServiceProvider;
use Jurager\Microservice\Support\Certificate;
use Jurager\Microservice\Support\Ecdsa;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /** Base64 CA private key, so tests can mint certificates for peers other than 'test-service'. */
    protected static string $caPrivateKeyEncoded;

    protected function getPackageProviders($app): array
    {
        return [
            MicroserviceServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $ca = self::generateKeyPair();
        static::$caPrivateKeyEncoded = $ca['private'];

        $service = self::generateKeyPair();
        $certificate = $this->issueCertificateFor('test-service', $service['public']);

        $app['config']->set('microservice.name', 'test-service');
        $app['config']->set('microservice.signing.private_key', $service['private']);
        $app['config']->set('microservice.signing.certificate', $certificate);
        $app['config']->set('microservice.signing.ca_public_key', $ca['public']);
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
     * Issue a certificate for an arbitrary service name, signed by the same
     * CA configured as SERVICE_CA_PUBLIC_KEY — for tests that need a peer
     * other than 'test-service'.
     */
    protected function issueCertificateFor(string $service, string $publicKeyEncoded): string
    {
        return Certificate::issue($service, $publicKeyEncoded, Ecdsa::loadPrivateKey(static::$caPrivateKeyEncoded))->encode();
    }

    /** @return array{private: string, public: string} Base64-wrapped EC key pair. */
    protected static function generateKeyPair(): array
    {
        return Ecdsa::generateKeyPair();
    }
}
