<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Tests\TestCase;

class AuthorityGenerateCommandTest extends TestCase
{
    public function test_prints_a_ca_key_pair(): void
    {
        $this->artisan('microservice:authority:generate')
            ->expectsOutputToContain('CA private key')
            ->expectsOutputToContain('CA public key')
            ->assertSuccessful();
    }

    public function test_warns_to_keep_the_private_key_offline(): void
    {
        $this->artisan('microservice:authority:generate')
            ->expectsOutputToContain('Never share the private key')
            ->assertSuccessful();
    }
}
