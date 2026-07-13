<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Throwable;

/**
 * Lazy AMQP connection wrapper over php-amqplib.
 *
 * Holds a single AMQPStreamConnection + Channel pair, opened on first use
 * and reused for the lifetime of the process. Declares the topic exchange
 * once on first channel access — both publishers and consumers go through this.
 */
class Connection
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /**
     * @throws Exception
     */
    public function channel(): AMQPChannel
    {
        if ($this->channel !== null && $this->connection !== null && $this->connection->isConnected()) {
            return $this->channel;
        }

        // Reset stale handles before reconnecting.
        if ($this->channel !== null || $this->connection !== null) {
            $this->close();
        }

        $cfg = (array) config('microservice.bus.connection', []);

        $this->connection = new AMQPStreamConnection(
            $cfg['host'] ?? '127.0.0.1',
            (int) ($cfg['port'] ?? 5672),
            $cfg['user'] ?? 'guest',
            $cfg['password'] ?? 'guest',
            $cfg['vhost'] ?? '/',
            insist: false,
            login_method: 'AMQPLAIN',
            login_response: null,
            locale: 'en_US',
            connection_timeout: (float) ($cfg['connection_timeout'] ?? 10),
            read_write_timeout: (float) ($cfg['read_write_timeout'] ?? ((int) ($cfg['heartbeat'] ?? 60) * 2 + 10)),
            context: null,
            keepalive: (bool) ($cfg['keepalive'] ?? true),
            heartbeat: (int) ($cfg['heartbeat'] ?? 60),
        );

        $this->channel = $this->connection->channel();
        $this->channel->exchange_declare(
            $this->exchange(),
            'topic',
            durable: true,
            auto_delete: false,
        );

        return $this->channel;
    }

    public function exchange(): string
    {
        return (string) config('microservice.bus.exchange', 'events');
    }

    public function close(): void
    {
        if ($this->channel !== null) {
            try {
                $this->channel->close();
            } catch (Throwable) {
            }
        }

        if ($this->connection !== null) {
            try {
                $this->connection->close();
            } catch (Throwable) {
            }
        }

        $this->channel = null;
        $this->connection = null;
    }
}
