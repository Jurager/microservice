<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Illuminate\Container\Attributes\Config;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use JsonException;
use Jurager\Microservice\Signing\Signer;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publish signed events through RabbitMQ.
 */
readonly class MessageBus
{
    /**
     * @param  bool  $enabled  Publish at all; when false, publish() only logs.
     * @param  string  $service  This service's name, carried by every envelope.
     * @param  int  $attempts  Publish attempts, counting the retry on a reopened connection.
     * @param  int  $confirmTimeout  Seconds to wait for the broker to confirm a publish.
     * @param  bool  $afterCommit  Inside a database transaction, publish once it commits — never for work rolled back.
     */
    public function __construct(
        private Signer $signer,
        private Connection $connection,
        private LoggerInterface $logger,
        private ConnectionResolverInterface $db,
        #[Config('microservice.bus.enabled', true)] private bool $enabled = true,
        #[Config('microservice.name', 'app')] private string $service = 'app',
        #[Config('microservice.bus.publish_attempts', 2)] private int $attempts = 2,
        #[Config('microservice.bus.confirm_timeout', 5)] private int $confirmTimeout = 5,
        #[Config('microservice.bus.after_commit', true)] private bool $afterCommit = true,
    ) {
    }

    /**
     * Publish an event after the current transaction commits, when configured.
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

        if ($this->afterCommit) {
            $this->db->connection()->afterCommit(fn () => $this->deliver($type, $envelope));

            return;
        }

        $this->deliver($type, $envelope);
    }

    /**
     * Verify an envelope's signature against the public key certified for the publishing service.
     */
    public function verify(array $envelope): bool
    {
        if (! $this->signer->enabled()) {
            return true;
        }

        $signature = $envelope['signature'] ?? null;
        $service = $envelope['service'] ?? null;
        $certificate = $envelope['certificate'] ?? null;

        if (! is_string($signature) || $signature === ''
            || ! is_string($service) || $service === ''
            || ! is_string($certificate) || $certificate === '') {
            return false;
        }

        try {
            return $this->signer->verifyRaw(self::canonicalize($envelope), $signature, $certificate, $service);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check if the message bus is enabled.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Send an envelope, retrying once on a reopened connection.
     *
     * @throws Throwable
     */
    private function deliver(string $type, array $envelope): void
    {
        $attempts = max(1, $this->attempts);

        for ($attempt = 1; ; $attempt++) {
            try {
                $this->send($type, $envelope);

                return;
            } catch (Throwable $e) {
                $this->connection->close();

                // An unroutable event is not worth retrying: the connection is fine,
                // there is simply no queue bound to the type yet.
                if ($attempt >= $attempts || ! $e instanceof AMQPExceptionInterface) {
                    $this->logger->error('MessageBus: failed to publish', ['type' => $type, 'error' => $e->getMessage()]);

                    throw $e;
                }

                $this->logger->warning('MessageBus: retrying on a new connection', ['type' => $type, 'error' => $e->getMessage()]);
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

        $channel->basic_publish($this->message($envelope), $this->connection->exchange(), $type, mandatory: true);

        // Confirms and returns arrive on their own frames, so an event that
        // reached no queue would go unnoticed without waiting for them.
        $channel->wait_for_pending_acks_returns($this->confirmTimeout);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function signedEnvelope(string $type, array $payload): array
    {
        $envelope = [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'service' => $this->service,
            'occurred_at' => now()->toIso8601String(),
            'request_id' => Context::get('request_id'),
            'payload' => $payload,
        ];

        $envelope['signature'] = $this->signer->signRaw(self::canonicalize($envelope));
        $envelope['certificate'] = $this->signer->certificate();

        return $envelope;
    }

    /** @throws JsonException */
    private function message(array $envelope): AMQPMessage
    {
        return new AMQPMessage(
            json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $envelope['id'],
            ],
        );
    }

    /**
     * Encode the signed envelope fields, excluding the signature and certificate.
     *
     * @throws JsonException
     */
    private static function canonicalize(array $envelope): string
    {
        return json_encode([
            'id' => $envelope['id'] ?? null,
            'type' => $envelope['type'] ?? null,
            'service' => $envelope['service'] ?? null,
            'occurred_at' => $envelope['occurred_at'] ?? null,
            'request_id' => $envelope['request_id'] ?? null,
            'payload' => $envelope['payload'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
