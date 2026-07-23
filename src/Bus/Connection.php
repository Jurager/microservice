<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

/** Manage lazy AMQP connections and channels. */
class Connection
{
    /**
     * Active AMQP stream connection.
     *
     * @var AMQPStreamConnection|null
     */
    private ?AMQPStreamConnection $connection = null;

    /**
     * Active AMQP channel.
     *
     * @var AMQPChannel|null
     */
    private ?AMQPChannel $channel = null;

    /**
     * Get active AMQP channel, connecting if necessary.
     *
     * @param int|null $heartbeat Overrides the configured heartbeat for a newly opened connection.
     * @throws Exception
     */
    public function channel(?int $heartbeat = null): AMQPChannel
    {
        if ($this->channel !== null && $this->isConnected()) {
            return $this->channel;
        }

        $this->close();

        $this->connection = $this->createConnection($heartbeat);
        $this->channel = $this->connection->channel();

        $this->channel->exchange_declare(
            $this->exchange(),
            'topic',
            durable: true,
            auto_delete: false,
        );

        return $this->channel;
    }

    /** Get configured exchange name. */
    public function exchange(): string
    {
        return (string) config('microservice.bus.exchange', 'events');
    }

    /** Close active channel and connection gracefully. */
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

    /** Check if the underlying AMQP connection is established and active. */
    private function isConnected(): bool
    {
        return $this->connection !== null && $this->connection->isConnected();
    }

    /** Create a new AMQP stream connection from config. */
    private function createConnection(?int $heartbeatOverride): AMQPStreamConnection
    {
        $cfg = (array) config('microservice.bus.connection', []);
        $configuredHeartbeat = (int) ($cfg['heartbeat'] ?? 60);
        $heartbeat = $heartbeatOverride ?? $configuredHeartbeat;

        return new AMQPStreamConnection(
            $cfg['host'] ?? '127.0.0.1',
            (int) ($cfg['port'] ?? 5672),
            $cfg['user'] ?? 'guest',
            $cfg['password'] ?? 'guest',
            $cfg['vhost'] ?? '/',
            insist: false,
            login_method: 'AMQPLAIN',
            login_response: null,
            locale: 'en_US',
            connection_timeout: (float) ($cfg['connection_timeout'] ?? 10.0),
            read_write_timeout: (float) ($cfg['read_write_timeout'] ?? ($configuredHeartbeat * 2 + 10.0)),
            context: null,
            keepalive: (bool) ($cfg['keepalive'] ?? true),
            heartbeat: $heartbeat,
        );
    }
}