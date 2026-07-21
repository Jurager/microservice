<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use JsonException;
use Jurager\Microservice\Support\HmacSigner;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AMQP-backed inter-service message bus.
 */
readonly class MessageBus
{
    public function __construct(
        private HmacSigner $signer,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Publish an event to the message bus.
     */
    public function publish(string $type, array $payload, ?string $queue = null): void
    {
        if (! $this->enabled()) {
            $this->logger->debug('Message bus publishing skipped.', [
                'type' => $type,
            ]);

            return;
        }

        try {
            $envelope = $this->signedEnvelope($type, $payload);

            $this->connection
                ->channel()
                ->basic_publish(
                    $this->message($envelope),
                    $this->connection->exchange(),
                    $type,
                );
        } catch (Throwable $e) {
            // Drop the handle so the next publish reconnects instead of reusing
            // a connection the broker (or network) has already torn down.
            $this->connection->close();

            $this->logger->error('MessageBus: failed to publish', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Verify envelope HMAC signature.
     */
    public function verify(array $envelope): bool
    {
        if (config('microservice.debug', false)) {
            return true;
        }

        $signature = $envelope['signature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        unset($envelope['signature']);

        try {
            return $this->signer->verifyRaw(
                self::canonicalize($envelope),
                $signature,
            );
        } catch (Throwable) {
            return false;
        }
    }

    public function enabled(): bool
    {
        return (bool) config('microservice.bus.enabled', true);
    }

    /**
     * Create signed message envelope.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function signedEnvelope(string $type, array $payload): array
    {
        $envelope = [
            'type' => $type,
            'service' => (string) config('microservice.name', 'app'),
            'occurred_at' => now()->toIso8601String(),
            'request_id' => request()?->header('X-Request-Id'),
            'payload' => $payload,
        ];

        $envelope['signature'] = $this->signer->signRaw(
            self::canonicalize($envelope),
        );

        return $envelope;
    }

    /**
     * Create AMQP message instance.
     *
     * @throws JsonException
     */
    private function message(array $envelope): AMQPMessage
    {
        return new AMQPMessage(
            json_encode(
                $envelope,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR,
            ),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ],
        );
    }

    /**
     * Create deterministic JSON representation for HMAC signing.
     *
     * @throws JsonException
     */
    private static function canonicalize(array $envelope): string
    {
        return json_encode(
            [
                'type' => $envelope['type'] ?? null,
                'service' => $envelope['service'] ?? null,
                'occurred_at' => $envelope['occurred_at'] ?? null,
                'request_id' => $envelope['request_id'] ?? null,
                'payload' => $envelope['payload'] ?? null,
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR,
        );
    }
}
