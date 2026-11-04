<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Tests\TestCase;

/**
 * The listener must reject invalid signing configuration before consuming messages.
 */
class ListenCommandSigningTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('microservice.signing.private_key', '');
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
