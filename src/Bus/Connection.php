<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

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
     * @throws Exception
     */
    public function channel(): AMQPChannel
    {
        // Explicit null check helps static analyzers (PHPStan/Psalm) infer the return type.
        if ($this->channel !== null && $this->isConnected()) {
            return $this->channel;
        }

        $this->close();

        $this->connection = $this->createConnection();
        $this->channel = $this->connection->channel();

        $this->channel->exchange_declare(
            $this->exchange(),
            'topic',
            durable: true,
            auto_delete: false,
        );

        return $this->channel;
    }

    /** Service AMQP heartbeats on an open connection via non-blocking read. */
    public function pulse(): void
    {
        if ($this->channel === null || ! $this->isConnected()) {
            return;
        }

        try {
            $this->channel->wait(null, true, 0);
            $this->connection->checkHeartBeat();
        } catch (Throwable) {
            $this->close();
        }
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
    private function createConnection(): AMQPStreamConnection
    {
        $cfg = (array) config('microservice.bus.connection', []);
        $heartbeat = (int) ($cfg['heartbeat'] ?? 60);

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
            read_write_timeout: (float) ($cfg['read_write_timeout'] ?? ($heartbeat * 2 + 10.0)),
            context: null,
            keepalive: (bool) ($cfg['keepalive'] ?? true),
            heartbeat: $heartbeat,
        );
    }
}