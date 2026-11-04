<?php

declare(strict_types=1);

namespace Jurager\Microservice\Health\Checks;

use Illuminate\Container\Attributes\Config;
use Jurager\Microservice\Bus\ConnectionFactory;
use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Bus\Topology;
use Jurager\Microservice\Health\Contracts\DependencyCheck;
use Jurager\Microservice\Health\DependencyStatus;
use PhpAmqpLib\Connection\AbstractConnection;
use Throwable;

/**
 * Check RabbitMQ availability and dead-letter queue depth.
 */
class BrokerCheck implements DependencyCheck
{
    /**
     * @param  bool  $bus  Whether the message bus is enabled.
     * @param  float  $timeout  Seconds to wait for the broker before giving up.
     */
    public function __construct(
        private readonly ConnectionFactory $connections,
        private readonly HandlerDiscovery $handlers,
        private readonly Topology $topology,
        #[Config('microservice.bus.enabled', true)] private readonly bool $bus = true,
        #[Config('microservice.bus.connection.connection_timeout', 10)] private readonly float $timeout = 10,
    ) {
    }

    public function name(): string
    {
        return 'rabbitmq';
    }

    public function critical(): bool
    {
        return false;
    }

    public function enabled(): bool
    {
        return $this->bus;
    }

    public function check(): DependencyStatus
    {
        $started = microtime(true);

        try {
            // A probe must not hang for a whole heartbeat period while the broker is unreachable.
            $connection = $this->connections->make(readWriteTimeout: $this->timeout);
        } catch (Throwable $e) {
            return DependencyStatus::down($e);
        }

        $latency = round((microtime(true) - $started) * 1000, 2);

        try {
            $deadLetters = $this->deadLetters($connection);
        } finally {
            $connection->close();
        }

        return DependencyStatus::up($latency, ['dead_letters' => $deadLetters], degraded: array_sum($deadLetters) > 0);
    }

    /**
     * Read dead-letter counts using a separate passive channel for each queue.
     *
     * @return array<string, int>
     */
    private function deadLetters(AbstractConnection $connection): array
    {
        if (! $this->topology->deadLettering()) {
            return [];
        }

        $depths = [];

        try {
            $types = $this->types();
        } catch (Throwable) {
            $types = [];
        }

        foreach ($types as $type) {
            $queue = $this->topology->deadLetterQueue($type);

            try {
                $channel = $connection->channel();
                $info = $channel->queue_declare($queue, passive: true);
                $depths[$queue] = is_array($info) ? (int) ($info[1] ?? 0) : 0;
                $channel->close();
            } catch (Throwable) {
                // The queue does not exist yet: nothing has been dead-lettered.
            }
        }

        return $depths;
    }

    /** @return list<string> */
    private function types(): array
    {
        $types = [];

        foreach ($this->handlers->discover() as $handler) {
            array_push($types, ...(array) $handler::type());
        }

        return array_values(array_unique($types));
    }
}
