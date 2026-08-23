<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Tests\TestCase;

class KeygenCommandTest extends TestCase
{
    public function test_show_prints_a_service_key_pair_without_touching_env(): void
    {
        $this->artisan('microservice:keygen', ['--show' => true])
            ->expectsOutputToContain('Public key')
            ->expectsOutputToContain('Get it certified')
            ->assertSuccessful();
    }

    public function test_writes_to_env_file_when_none_exists_yet(): void
    {
        $path = $this->app->environmentFilePath();
        $this->assertFalse(is_file($path), 'Test expects no .env file to pre-exist.');

        try {
            file_put_contents($path, "APP_NAME=test\n");

            $this->artisan('microservice:keygen')
                ->expectsOutputToContain('SERVICE_PRIVATE_KEY set in .env')
                ->assertSuccessful();

            $this->assertMatchesRegularExpression('/^SERVICE_PRIVATE_KEY=.+$/m', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_prompts_before_overwriting_an_existing_key_in_env(): void
    {
        $path = $this->app->environmentFilePath();

        try {
            file_put_contents($path, "APP_NAME=test\nSERVICE_PRIVATE_KEY=old-value\n");

            $this->artisan('microservice:keygen')
                ->expectsConfirmation('SERVICE_PRIVATE_KEY already set in .env. Overwrite it?', 'no')
                ->expectsOutputToContain('SERVICE_PRIVATE_KEY was not written automatically')
                ->assertSuccessful();

            $this->assertStringContainsString('SERVICE_PRIVATE_KEY=old-value', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }
}
