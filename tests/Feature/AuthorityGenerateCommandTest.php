<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Tests\TestCase;

class AuthorityGenerateCommandTest extends TestCase
{
    public function test_prints_a_ca_key_pair(): void
    {
        $this->artisan('microservice:authority:generate')
            ->expectsOutputToContain('Cluster CA generated')
            ->expectsOutputToContain('CA private key')
            ->expectsOutputToContain('CA public key')
            ->assertSuccessful();
    }

    public function test_never_touches_the_env_file(): void
    {
        $path = $this->app->environmentFilePath();
        $this->assertFalse(is_file($path), 'Test expects no .env file to pre-exist.');

        $this->artisan('microservice:authority:generate')->assertSuccessful();

        $this->assertFalse(is_file($path), 'microservice:authority:generate must never write to .env.');
    }
}
