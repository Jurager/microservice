<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ExceptionReportingTest extends TestCase
{
    #[DataProvider('serverFailures')]
    public function test_server_failures_are_logged_with_the_exception(Throwable $error): void
    {
        Log::spy();

        $this->app->make(ExceptionHandler::class)->report($error);

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $message === $error->getMessage()
            && $context['exception'] === $error);
    }

    public static function serverFailures(): array
    {
        return [
            'HTTP 500' => [new HttpException(500, 'Health check failed.')],
            'upstream 500' => [new ServiceRequestException(500, service: 'cms')],
            'upstream authentication failed' => [new ServiceRequestException(401, service: 'pim')],
            'upstream unavailable' => [new ServiceUnavailableException('sfm')],
        ];
    }

    public function test_client_errors_are_not_logged(): void
    {
        Log::spy();
        $handler = $this->app->make(ExceptionHandler::class);

        foreach ([400, 401, 403, 404, 405, 419, 422, 429] as $status) {
            $handler->report(new HttpException($status));
        }

        $handler->report(new ServiceRequestException(422, service: 'cms'));

        Log::shouldNotHaveReceived('error');
    }

    public function test_upstream_status_and_service_are_in_the_log_context(): void
    {
        Log::spy();
        $error = new ServiceRequestException(500, service: 'cms');

        $this->app->make(ExceptionHandler::class)->report($error);

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['upstream_service'] === 'cms'
            && $context['upstream_status'] === 500
            && $context['http_status'] === 502);
    }

    public function test_reporting_does_not_require_the_json_api_renderer(): void
    {
        config(['microservice.render_exceptions' => false]);
        Log::spy();

        $this->app->make(ExceptionHandler::class)->report(new ServiceUnavailableException('sfm'));

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['upstream_service'] === 'sfm'
            && $context['http_status'] === 502);
    }
}
