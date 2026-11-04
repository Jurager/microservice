<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Illuminate\Container\Attributes\Config;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Declare service queues and their dead-letter bindings.
 */
final readonly class Topology
{
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
     * Declare and bind the queue of an event type, and its dead-letter queue.
     */
    public function declareQueue(AMQPChannel $channel, string $type): void
    {
        $arguments = [];

        if ($this->deadLetterExchange !== null) {
            $channel->queue_declare($this->deadLetterQueue($type), durable: true, auto_delete: false);
            $channel->queue_bind($this->deadLetterQueue($type), $this->deadLetterExchange, $type);

            $arguments = new AMQPTable(['x-dead-letter-exchange' => $this->deadLetterExchange]);
        }

        $channel->queue_declare($this->queue($type), durable: true, auto_delete: false, arguments: $arguments);
        $channel->queue_bind($this->queue($type), $this->exchange, $type);
    }
}
