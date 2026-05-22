<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Throwable;

/**
 * Dispatches an incoming envelope to the right MessageHandler:
 *  - verifies the HMAC signature (drops on invalid/missing)
 *  - constructs the handler via fromMessage()
 *  - routes to Laravel queue when ShouldQueue, otherwise invokes handle() inline
 *
 * Decoupled from the AMQP layer so ListenCommand stays focused on transport
 * concerns and this class is unit-testable without a broker.
 */
class Listener
{
    public function __construct(private readonly MessageBus $bus)
    {
    }

    /**
     * Process one envelope for a handler class.
     * Returns true on success (caller should ack), false on rejection/error
     * (caller decides — typically ack to avoid poison-message loops + log).
     */
    public function handle(string $handlerClass, array $envelope): bool
    {
        if (! is_subclass_of($handlerClass, MessageHandler::class)) {
            Log::error('Listener: not a MessageHandler', ['class' => $handlerClass]);

            return false;
        }

        if (! $this->bus->verify($envelope)) {
            Log::warning('Listener: rejected envelope with invalid or missing signature', [
                'type' => $envelope['type'] ?? null,
            ]);

            return false;
        }

        $payload = $envelope['payload'] ?? [];

        try {
            /** @var MessageHandler $handler */
            $handler = $handlerClass::fromMessage(is_array($payload) ? $payload : []);

            if ($handler instanceof ShouldQueue) {
                dispatch($handler);

                return true;
            }

            if (method_exists($handler, 'handle')) {
                $handler->handle();
            }

            return true;
        } catch (Throwable $e) {
            Log::error('Listener: handler threw', [
                'class' => $handlerClass,
                'type'  => $envelope['type'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
