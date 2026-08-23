<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Tests\TestCase;
use RuntimeException;
use Throwable;

/**
 * The provider validates signing config at boot only outside the console
 * (see MicroserviceServiceProvider::boot()) — an HTTP-serving process is
 * the case that benefits from crashing immediately on bad config rather
 * than serving broken requests. `APP_RUNNING_IN_CONSOLE=false` is Laravel's
 * own documented way to simulate that in a CLI test run.
 *
 * ListenCommandSigningTest's setUp() booting successfully with blank
 * signing config already proves the console side is *not* validated here;
 * this is the other half.
 */
class SigningValidatedOutsideConsoleTest extends TestCase
{
    private ?Throwable $bootException = null;

    protected function setUp(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE=false');

        try {
            parent::setUp();
        } catch (Throwable $e) {
            $this->bootException = $e;
        } finally {
            putenv('APP_RUNNING_IN_CONSOLE');
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('microservice.signing.private_key', '');
        $app['config']->set('microservice.signing.certificate', '');
        $app['config']->set('microservice.signing.ca_public_key', '');
    }

    public function test_boot_throws_when_not_running_in_console_and_signing_is_unconfigured(): void
    {
        $this->assertInstanceOf(RuntimeException::class, $this->bootException);
        $this->assertStringContainsString('Invalid signing configuration', $this->bootException->getMessage());
    }
}
