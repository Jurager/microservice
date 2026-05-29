<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Throwable;

/**
 * Validates and dispatches incoming message envelopes
 * to their corresponding handlers.
 */
readonly class Listener
{
    public function __construct(
        private MessageBus $bus,
    ) {
    }

    /**
     * Handle a single message envelope.
     *
     * Returns:
     *  - true  => message processed successfully
     *  - false => invalid message or handler failure
     */
    public function handle(string $handlerClass, array $envelope): bool
    {
        if (! is_subclass_of($handlerClass, MessageHandler::class)) {
            Log::error('Listener: not a MessageHandler', [
                'class' => $handlerClass,
            ]);

            return false;
        }

        if (! $this->bus->verify($envelope)) {
            Log::warning('Listener: rejected envelope with invalid or missing signature', [
                'type' => $envelope['type'] ?? null,
            ]);

            return false;
        }

        try {
            $handler = $handlerClass::from($this->payload($envelope));

            if ($handler instanceof ShouldQueue) {
                dispatch($handler);

                return true;
            }

            $handler->handle();

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

    /**
     * Extract normalized payload from envelope.
     */
    private function payload(array $envelope): array
    {
        $payload = $envelope['payload'] ?? [];

        return is_array($payload) ? $payload : [];
    }
}
