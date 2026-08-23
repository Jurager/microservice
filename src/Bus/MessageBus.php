<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use JsonException;
use Jurager\Microservice\Support\Signer;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Throwable;

/** AMQP-backed inter-service message bus. */
readonly class MessageBus
{
    public function __construct(
        private Signer $signer,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Publish an event to the message bus.
     *
     * @throws Throwable
     */
    public function publish(string $type, array $payload): void
    {
        if (! $this->enabled()) {
            $this->logger->debug('MessageBus: publishing disabled', ['type' => $type]);

            return;
        }

        // Signed once, so a retry sends the same event rather than a new one.
        $envelope = $this->signedEnvelope($type, $payload);

        $attempts = max(1, (int) config('microservice.bus.publish_attempts', 2));

        for ($attempt = 1; ; $attempt++) {
            try {
                $this->send($type, $envelope);

                return;
            } catch (Throwable $e) {
                $this->connection->close();

                // An unroutable event is not worth retrying: the connection is fine,
                // there is simply no queue bound to the type yet.
                if ($attempt >= $attempts || ! $e instanceof AMQPExceptionInterface) {
                    $this->logger->error('MessageBus: failed to publish', [
                        'type' => $type,
                        'error' => $e->getMessage(),
                    ]);

                    throw $e;
                }

                $this->logger->warning('MessageBus: retrying on a new connection', [
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send the envelope and wait for the broker to account for it.
     *
     * @throws Throwable
     */
    private function send(string $type, array $envelope): void
    {
        $channel = $this->connection->channel();

        $channel->basic_publish(
            $this->message($envelope),
            $this->connection->exchange(),
            $type,
            mandatory: true,
        );

        // Confirms and returns arrive on their own frames, so an event that
        // reached no queue would go unnoticed without waiting for them.
        $channel->wait_for_pending_acks_returns(
            (int) config('microservice.bus.confirm_timeout', 5),
        );
    }

    /** Verify envelope signature against the secret configured for the publishing service. */
    public function verify(array $envelope): bool
    {
        if (config('microservice.debug', false) === true) {
            return true;
        }

        $signature = $envelope['signature'] ?? null;
        $service = $envelope['service'] ?? null;

        if (! is_string($signature) || $signature === ''
            || ! is_string($service) || $service === '') {
            return false;
        }

        try {
            return $this->signer->verifyRaw(
                self::canonicalize($envelope),
                $signature,
                $service,
            );
        } catch (Throwable) {
            return false;
        }
    }

    /** Check if the message bus is enabled. */
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
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        return new AMQPMessage(
            json_encode($envelope, $flags),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ],
        );
    }

    /**
     * Create deterministic JSON representation for signing.
     *
     * @throws JsonException
     */
    private static function canonicalize(array $envelope): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        return json_encode(
            [
                'type' => $envelope['type'] ?? null,
                'service' => $envelope['service'] ?? null,
                'occurred_at' => $envelope['occurred_at'] ?? null,
                'request_id' => $envelope['request_id'] ?? null,
                'payload' => $envelope['payload'] ?? null,
            ],
            $flags,
        );
    }
}
