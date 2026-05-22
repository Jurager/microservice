<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Illuminate\Support\Facades\Log;
use Jurager\Microservice\Support\HmacSigner;
use Throwable;

/**
 * Inter-service message bus over bunny/bunny (AMQP).
 *
 * Wraps a domain payload in a standard HMAC-signed envelope and publishes
 * it to a topic exchange with the event type as the routing key.
 *
 * Disabled via `microservice.bus.enabled = false`, in which case publish()
 * is a no-op (logged) — useful for services that don't need RabbitMQ at all.
 */
class MessageBus
{
    public function __construct(
        private readonly HmacSigner $signer,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Publish an event to the bus.
     *
     * @param  string       $type     Event name (e.g. "sfm.site.updated").
     * @param  array        $payload  Domain payload — wrapped into a standard envelope.
     * @param  string|null  $queue    Deprecated/ignored. Kept for backward compatibility.
     */
    public function publish(string $type, array $payload, ?string $queue = null): void
    {
        if (! $this->enabled()) {
            Log::debug('MessageBus: publish skipped (disabled)', ['type' => $type]);

            return;
        }

        try {
            $envelope = $this->envelope($type, $payload);
            $envelope['signature'] = $this->signer->signRaw(self::canonicalize($envelope));

            $body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $message = new \PhpAmqpLib\Message\AMQPMessage($body, [
                'content_type'  => 'application/json',
                'delivery_mode' => \PhpAmqpLib\Message\AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]);

            $this->connection->channel()->basic_publish(
                $message,
                $this->connection->exchange(),
                $type,
            );
        } catch (Throwable $e) {
            Log::error('MessageBus: failed to publish', [
                'type'  => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verify the HMAC signature of a received envelope.
     * Skipped when microservice.debug is enabled, mirroring HTTP middleware behavior.
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
            return $this->signer->verifyRaw(self::canonicalize($envelope), $signature);
        } catch (Throwable) {
            return false;
        }
    }

    public function enabled(): bool
    {
        return (bool) config('microservice.bus.enabled', true);
    }

    /**
     * @return array{type: string, service: string, occurred_at: string, request_id: ?string, payload: array}
     */
    private function envelope(string $type, array $payload): array
    {
        return [
            'type'        => $type,
            'service'     => (string) config('microservice.name', 'app'),
            'occurred_at' => now()->toIso8601String(),
            'request_id'  => request()?->header('X-Request-Id'),
            'payload'     => $payload,
        ];
    }

    /**
     * Canonical string form of an envelope (without signature) for HMAC.
     * Both publisher and verifier must produce identical bytes — fixed key order
     * is enforced here so it doesn't depend on insertion order at call sites.
     */
    private static function canonicalize(array $envelope): string
    {
        $canonical = [
            'type'        => $envelope['type']        ?? null,
            'service'     => $envelope['service']     ?? null,
            'occurred_at' => $envelope['occurred_at'] ?? null,
            'request_id'  => $envelope['request_id'] ?? null,
            'payload'     => $envelope['payload']     ?? null,
        ];

        return json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
