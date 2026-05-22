<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Bunny\Channel;
use Bunny\Client;

/**
 * Lazy bunny AMQP connection wrapper.
 *
 * Holds a single Client + Channel pair, opened on first use and reused for
 * the lifetime of the process. Declares the topic exchange once on first
 * channel access — both publishers and consumers go through this.
 */
class Connection
{
    private ?Client $client = null;

    private ?Channel $channel = null;

    public function channel(): Channel
    {
        if ($this->channel !== null) {
            return $this->channel;
        }

        $this->client = new Client($this->options());
        $this->client->connect();

        $this->channel = $this->client->channel();
        $this->channel->exchangeDeclare(
            $this->exchange(),
            'topic',
            passive: false,
            durable: true,
            autoDelete: false,
        );

        return $this->channel;
    }

    public function client(): Client
    {
        $this->channel(); // ensure connection is open

        /** @var Client */
        return $this->client;
    }

    public function exchange(): string
    {
        return (string) config('microservice.bus.exchange', 'events');
    }

    public function close(): void
    {
        if ($this->client !== null && $this->client->isConnected()) {
            try {
                $this->client->disconnect();
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }

        $this->channel = null;
        $this->client = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $cfg = (array) config('microservice.bus.connection', []);

        return [
            'host'               => $cfg['host']               ?? '127.0.0.1',
            'port'               => (int) ($cfg['port']        ?? 5672),
            'user'               => $cfg['user']               ?? 'guest',
            'password'           => $cfg['password']           ?? 'guest',
            'vhost'              => $cfg['vhost']              ?? '/',
            'heartbeat'          => (int) ($cfg['heartbeat']   ?? 60),
            'timeout'            => (int) ($cfg['connection_timeout'] ?? 10),
        ];
    }
}
