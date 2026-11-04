<?php

declare(strict_types=1);

namespace Jurager\Microservice\Facades;

use Illuminate\Support\Facades\Facade;
use Jurager\Microservice\Bus\MessageBus as Bus;

/**
 * Publish signed events to other services.
 *
 * @method static void publish(string $type, array $payload)
 * @method static bool verify(array $envelope)
 * @method static bool enabled()
 *
 * @see Bus
 */
class MessageBus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Bus::class;
    }
}
