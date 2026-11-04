<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Jurager\Microservice\Client\CircuitBreaker;
use Jurager\Microservice\Signing\Signer;
use Jurager\Microservice\Tests\TestCase;
use RuntimeException;

/**
 * Validate configuration when used, allowing unrelated commands and routes to run.
 */
class ConfigurationCheckedOnUseTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('microservice.signing.private_key', '');
        $app['config']->set('app.env', 'production');
        $app['config']->set('cache.stores.local', ['driver' => 'file', 'path' => sys_get_temp_dir()]);
        $app['config']->set('microservice.cache.store', 'local');
    }

    public function test_the_application_boots_and_runs_commands(): void
    {
        $this->assertSame(0, Artisan::call('list'));
    }

    public function test_signing_fails_when_the_service_first_signs(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SERVICE_PRIVATE_KEY is not configured');

        $this->app->make(Signer::class)->sign('GET', '/v1/orders', (string) time());
    }

    public function test_a_local_cache_fails_the_component_that_needs_a_shared_one(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a cache shared by every instance');

        $this->app->make(CircuitBreaker::class);
    }

    public function test_about_reports_what_is_not_set_up(): void
    {
        Artisan::call('about', ['--only' => 'microservice']);

        $output = Artisan::output();

        $this->assertStringContainsString('SERVICE_PRIVATE_KEY is not configured', $output);
        $this->assertStringContainsString('needs a cache shared by every instance', $output);
    }
}
