<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Closure;
use Illuminate\Container\Attributes\Config;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Declare service queues and their dead-letter bindings.
 */
final readonly class Topology
{
    private const int PRECONDITION_FAILED = 406;

    /**
     * The dead-letter exchange, or null while dead-lettering is off.
     */
    public ?string $deadLetterExchange;

    /**
     * @param  string  $service  This service's name, prefixing its queues.
     * @param  string  $exchange  Topic exchange events are published to.
     */
    public function __construct(
        #[Config('microservice.name', 'app')] public string $service = 'app',
        #[Config('microservice.bus.exchange', 'events')] public string $exchange = 'events',
        #[Config('microservice.bus.dead_letter.enabled', true)] bool $deadLettering = true,
        #[Config('microservice.bus.dead_letter.exchange', 'events.dlx')] string $deadLetterExchange = 'events.dlx',
    ) {
        $this->deadLetterExchange = $deadLettering ? $deadLetterExchange : null;
    }

    public function deadLettering(): bool
    {
        return $this->deadLetterExchange !== null;
    }

    public function queue(string $type): string
    {
        return "$this->service.$type";
    }

    public function deadLetterQueue(string $type): string
    {
        return $this->queue($type).'.dlq';
    }

    public function consumerTag(string $type): string
    {
        return "$this->service-$type";
    }

    /**
     * Declare the dead-letter exchange, when dead-lettering is on.
     */
    public function declareExchanges(AMQPChannel $channel): void
    {
        if ($this->deadLetterExchange !== null) {
            $channel->exchange_declare($this->deadLetterExchange, 'topic', durable: true, auto_delete: false);
        }
    }

    /**
     * Declare and bind the queue of an event type and its dead-letter queue, recreating an empty outdated queue.
     */
    public function declareQueue(AMQPChannel $channel, string $type): QueueDeclaration
    {
        $connection = $channel->getConnection();

        $declared = $this->onChannel($connection, function (AMQPChannel $channel) use ($type): bool {
            if ($this->deadLetterExchange !== null) {
                $channel->queue_declare($this->deadLetterQueue($type), durable: true, auto_delete: false);
                $channel->queue_bind($this->deadLetterQueue($type), $this->deadLetterExchange, $this->queue($type));
            }

            return $this->declareMainQueue($channel, $type);
        });

        if ($declared) {
            return QueueDeclaration::Declared;
        }

        $recreated = $this->onChannel($connection, function (AMQPChannel $channel) use ($type): bool {
            try {
                $channel->queue_delete($this->queue($type), if_empty: true);
            } catch (AMQPProtocolChannelException $e) {
                return self::refused($e);
            }

            return $this->declareMainQueue($channel, $type);
        });

        if (! $recreated) {
            return QueueDeclaration::Outdated;
        }

        if ($this->deadLetterExchange !== null) {
            try {
                $this->onChannel($connection, fn (AMQPChannel $channel) => $channel->queue_unbind($this->deadLetterQueue($type), $this->deadLetterExchange, $type));
            } catch (AMQPProtocolChannelException) {
            }
        }

        return QueueDeclaration::Recreated;
    }

    /**
     * Declare and bind the queue of an event type, or return false when it exists with other arguments.
     */
    private function declareMainQueue(AMQPChannel $channel, string $type): bool
    {
        $arguments = $this->deadLetterExchange === null ? [] : new AMQPTable([
            'x-dead-letter-exchange' => $this->deadLetterExchange,
            'x-dead-letter-routing-key' => $this->queue($type),
        ]);

        try {
            $channel->queue_declare($this->queue($type), durable: true, auto_delete: false, arguments: $arguments);
        } catch (AMQPProtocolChannelException $e) {
            return self::refused($e);
        }

        $channel->queue_bind($this->queue($type), $this->exchange, $type);

        return true;
    }

    /**
     * Run the callback on a new channel of the connection, closing it afterwards.
     *
     * @template T
     *
     * @param  Closure(AMQPChannel): T  $callback
     * @return T
     */
    private function onChannel(AbstractConnection $connection, Closure $callback): mixed
    {
        $channel = $connection->channel();

        try {
            return $callback($channel);
        } finally {
            try {
                $channel->close();
            } catch (Throwable) {
            }
        }
    }

    /**
     * Return false for a PRECONDITION_FAILED refusal, rethrowing anything else.
     *
     * @throws AMQPProtocolChannelException
     */
    private static function refused(AMQPProtocolChannelException $e): bool
    {
        if ($e->getCode() !== self::PRECONDITION_FAILED) {
            throw $e;
        }

        return false;
    }
}
