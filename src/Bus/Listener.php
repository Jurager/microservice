<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Context;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validates and dispatches incoming message envelopes
 * to their corresponding handlers.
 */
readonly class Listener
{
    public function __construct(
        private MessageBus $bus,
        private LoggerInterface $logger,
        private Container $container,
    ) {
    }

    /**
     * Handle a message envelope, returning false on invalid input or handler failure.
     */
    public function handle(string $handlerClass, array $envelope): bool
    {
        if (! is_subclass_of($handlerClass, MessageHandler::class)) {
            $this->logger->error('Listener: not a MessageHandler', [
                'class' => $handlerClass,
            ]);

            return false;
        }

        if (! $this->bus->verify($envelope)) {
            $this->logger->warning('Listener: rejected envelope with invalid or missing signature', [
                'type' => $envelope['type'] ?? null,
            ]);

            return false;
        }

        // What the message is, for the handler, its logs and the jobs it queues — a handler
        // that may see a message twice deduplicates by Context::get('message_id').
        Context::add(array_filter([
            'message_id' => $envelope['id'] ?? null,
            'message_type' => $envelope['type'] ?? null,
            'message_publisher' => $envelope['service'] ?? null,
            'request_id' => $envelope['request_id'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));

        try {
            $handler = $handlerClass::from($this->payload($envelope), (string) ($envelope['type'] ?? ''));

            if ($handler instanceof ShouldQueue) {
                dispatch($handler);

                return true;
            }

            // Resolved like a queued job's handle(): its dependencies are injected.
            $this->container->call([$handler, 'handle']);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Listener: handler threw', [
                'class' => $handlerClass,
                'type' => $envelope['type'] ?? null,
                'error' => $e->getMessage(),
                'exception' => $e,
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
