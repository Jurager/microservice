<?php

declare(strict_types=1);

namespace Jurager\Microservice\Facades;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Jurager\Microservice\Client\PendingServiceRequest;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Client\ServiceResponse;
use Jurager\Microservice\Testing\FakeServices;

/**
 * Send signed HTTP requests to other services.
 *
 * @method static PendingServiceRequest service(string $name)
 * @method static array<string|int, ServiceResponse> parallel(array<string|int, PendingServiceRequest> $requests, int $concurrency = 0)
 * @method static void forgetMemo(?string $service = null)
 *
 * @see ServiceClient
 */
class Microservice extends Facade
{
    /**
     * Queue fake responses for the given services using Laravel's HTTP client.
     *
     * @param  array<string, list<mixed>>  $services  Responses by service name.
     */
    public static function fake(array $services = []): FakeServices
    {
        $app = static::getFacadeApplication();

        if (! $app->bound(FakeServices::class)) {
            $app->instance(FakeServices::class, $app->make(FakeServices::class));
        }

        $fake = $app->make(FakeServices::class);

        foreach ($services as $service => $responses) {
            $fake->fake($service, $responses);
        }

        return $fake;
    }

    /** @param  callable(Request, string): bool  $callback  Receives the request and the service name. */
    public static function assertSent(callable $callback): void
    {
        static::fakes()->assertSent($callback);
    }

    /** @param  callable(Request, string): bool  $callback  Receives the request and the service name. */
    public static function assertNotSent(callable $callback): void
    {
        static::fakes()->assertNotSent($callback);
    }

    public static function assertSentCount(int $count): void
    {
        static::fakes()->assertSentCount($count);
    }

    public static function assertNothingSent(): void
    {
        static::fakes()->assertNothingSent();
    }

    private static function fakes(): FakeServices
    {
        return static::fake();
    }

    protected static function getFacadeAccessor(): string
    {
        return ServiceClient::class;
    }
}
