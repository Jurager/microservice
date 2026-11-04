<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use NunoMaduro\Collision\Adapters\Laravel\ExceptionHandler as CollisionHandler;

class CollisionExceptionReportingTest extends ExceptionReportingTest
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), CollisionHandlerProvider::class];
    }

    public function test_collision_can_wrap_the_handler_before_providers_boot(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $response = $handler->render(Request::create('/api/products'), new ServiceUnavailableException('pim'));

        $this->assertInstanceOf(CollisionHandler::class, $handler);
        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('502', $response->getData(true)['errors'][0]['status']);
    }
}

class CollisionHandlerProvider extends ServiceProvider
{
    public function register(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $this->app->singleton(ExceptionHandler::class, fn ($app) => new CollisionHandler($app, $handler));
    }
}
