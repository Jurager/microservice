<?php

declare(strict_types=1);

namespace Jurager\Microservice\Queue;

use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob;

class MessageJob extends RabbitMQJob
{
    /**
     * Intercept raw message bodies and translate them to Laravel job payloads.
     *
     * Messages published via MessageBus arrive as {"type": "...", "payload": {...}}.
     * Standard Laravel jobs dispatched directly via dispatch() already carry a "job"
     * key and pass through unchanged.
     */
    public function payload(): array
    {
        $raw = $this->getRawBody();
        $decoded = json_decode($raw, true);

        if (isset($decoded['job'])) {
            return $decoded;
        }

        return (new MessageDeserializer())->deserialize($raw);
    }
}
