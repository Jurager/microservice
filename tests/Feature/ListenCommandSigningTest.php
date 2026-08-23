<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Tests\TestCase;

/**
 * `microservice:listen` is a long-running daemon: if it started with a
 * broken Signer it would ack or nack every single event on bad
 * information. It must refuse to start instead — and since the provider
 * skips signing validation for console commands entirely (see
 * MicroserviceServiceProvider::boot()), the command has to assert this for
 * itself.
 */
class ListenCommandSigningTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('microservice.signing.private_key', '');
        $app['config']->set('microservice.signing.certificate', '');
        $app['config']->set('microservice.signing.ca_public_key', '');
    }

    public function test_refuses_to_start_when_signing_is_unconfigured(): void
    {
        $this->artisan('microservice:listen')
            ->assertFailed();
    }

    public function test_is_a_noop_when_bus_is_disabled_even_without_signing_configured(): void
    {
        // Bus-disabled is checked first — an entirely inactive bus has
        // nothing to sign or verify, so it shouldn't require signing either.
        config()->set('microservice.bus.enabled', false);

        $this->artisan('microservice:listen')
            ->assertFailed()
            ->expectsOutputToContain('MessageBus is disabled');
    }
}
