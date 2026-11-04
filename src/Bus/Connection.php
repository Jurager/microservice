<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Exception;
use Illuminate\Container\Attributes\Config;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use RuntimeException;
use Throwable;

class Connection
{
    /**
     * Active AMQP stream connection.
     */
    private ?AbstractConnection $connection = null;

    /**
     * Active AMQP channel.
     */
    private ?AMQPChannel $channel = null;

    /**
     * Time the channel was last handed out.
     */
    private ?float $usedAt = null;

    /**
     * @param  string  $exchange  Topic exchange events are published to.
     * @param  int  $maxIdle  Seconds a channel may sit unused before it is reopened.
     */
    public function __construct(
        private readonly ConnectionFactory $factory,
        #[Config('microservice.bus.exchange', 'events')] private readonly string $exchange = 'events',
        #[Config('microservice.bus.max_idle', 60)] private readonly int $maxIdle = 60,
    ) {
    }

    /**
     * Get active AMQP channel, connecting if necessary.
     *
     * @param  int|null  $heartbeat  Overrides the configured heartbeat for a newly opened connection.
     *
     * @throws Exception
     */
    public function channel(?int $heartbeat = null): AMQPChannel
    {
        if ($this->channel !== null && $this->isConnected() && ! $this->idled()) {
            $this->usedAt = microtime(true);

            return $this->channel;
        }

        $this->close();

        $this->connection = $this->factory->make($heartbeat);
        $this->channel = $this->prepare($this->connection);
        $this->usedAt = microtime(true);

        return $this->channel;
    }

    /**
     * Determine whether the connection has been idle for too long to trust.
     */
    private function idled(): bool
    {
        return $this->usedAt === null
            || (microtime(true) - $this->usedAt) > $this->maxIdle;
    }

    /**
     * Declare the exchange and enable delivery feedback on a new channel.
     */
    private function prepare(AbstractConnection $connection): AMQPChannel
    {
        $channel = $connection->channel();

        $channel->exchange_declare(
            $this->exchange(),
            'topic',
            durable: true,
            auto_delete: false,
        );

        // Returns are only tracked for confirmed publishes.
        $channel->confirm_select();

        // An event bound to no queue is returned instead of being dropped.
        $channel->set_return_listener(
            static function (int $code, string $text, string $exchange, string $routingKey): void {
                throw new RuntimeException(
                    "Message [$routingKey] was not routed to any queue: $text ($code)"
                );
            }
        );

        return $channel;
    }

    /**
     * Get configured exchange name.
     */
    public function exchange(): string
    {
        return $this->exchange;
    }

    /**
     * Close active channel and connection gracefully.
     */
    public function close(): void
    {
        if ($this->channel !== null) {
            try {
                $this->channel->close();
            } catch (Throwable) {
            }

            $this->channel = null;
        }

        if ($this->connection !== null) {
            try {
                $this->connection->close();
            } catch (Throwable) {
            }

            $this->connection = null;
        }
    }

    /**
     * Check if the underlying AMQP connection is established and active.
     */
    private function isConnected(): bool
    {
        return $this->connection !== null && $this->connection->isConnected();
    }
}
