<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Jurager\Microservice\Bus\Connection;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Bus\Listener;
use Jurager\Microservice\Bus\MessageBus;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPDataReadException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPIOWaitException;
use PhpAmqpLib\Exception\AMQPSocketException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Throwable;

#[Signature('microservice:listen
             {--memory=128 : Memory limit in MB; the worker stops when exceeded}
             {--max-jobs=0 : Stop after this many messages (0 = unlimited)}')]
#[Description('Listen for inter-service events from RabbitMQ.')]
class ListenCommand extends Command
{
    private bool $shouldStop = false;

    private int $processed = 0;

    private int $memoryLimit = 0;

    private int $maxJobs = 0;

    public function handle(MessageBus $bus, Connection $connection, Listener $listener, HandlerDiscovery $discovery, LoggerInterface $logger): void
    {
        if (! $bus->enabled()) {
            $this->fail('MessageBus is disabled (config: microservice.bus.enabled).');
        }

        $handlers = $this->discoverHandlers($discovery);

        if ($handlers === []) {
            $this->warn('No message handlers discovered — nothing to listen for.');

            return;
        }

        $this->trap([SIGTERM, SIGINT], fn () => $this->shouldStop = true);

        try {
            $channel = $connection->channel();
        } catch (Throwable $e) {
            $this->fail("Failed to connect to RabbitMQ: {$e->getMessage()}");
        }

        $exchange = $connection->exchange();
        $service = (string) config('microservice.name', 'app');
        $this->memoryLimit = (int) $this->input('memory');
        $this->maxJobs = (int) $this->input('max-jobs');
        $dlqEnabled = (bool) config('microservice.bus.dead_letter.enabled', true);
        $dlxName = (string) config('microservice.bus.dead_letter.exchange', 'events.dlx');

        if ($dlqEnabled) {
            $channel->exchange_declare($dlxName, 'topic', durable: true, auto_delete: false);
        }

        $channel->basic_qos(prefetch_size: 0, prefetch_count: 1, a_global: false);

        foreach ($handlers as $type => $class) {
            $this->declareQueues($channel, $service, $type, $exchange, $dlqEnabled, $dlxName);

            $channel->basic_consume(
                "$service.$type",
                consumer_tag: "$service-$type",
                callback: function (AMQPMessage $message) use ($class, $listener, $logger, $dlqEnabled): void {
                    $this->dispatch($message, $class, $listener, $logger, $dlqEnabled);
                    $this->checkStopConditions();
                },
            );
        }

        $this->info('Listening for events: '.implode(', ', array_keys($handlers))
            .($dlqEnabled ? " (DLQ → $dlxName)" : ''));

        while (! $this->shouldStop && $channel->is_consuming()) {
            try {
                $channel->wait(null, false, 1);
            } catch (AMQPTimeoutException) {
                // No message in window — normal, loop and check signals
            } catch (AMQPConnectionClosedException|AMQPDataReadException|AMQPSocketException|AMQPIOException|AMQPIOWaitException $e) {
                $logger->warning('ListenCommand: connection lost', ['error' => $e->getMessage()]);
                $this->closeQuietly($connection);
                $this->fail("Connection lost: {$e->getMessage()}");
            } catch (Throwable $e) {
                $logger->error('ListenCommand: AMQP loop error', ['error' => $e->getMessage()]);
                $this->closeQuietly($connection);
                $this->fail($e->getMessage());
            }

            // Memory can also grow while idle (e.g. queued dispatch buffers) —
            // re-check between wait windows, not only after a message.
            $this->checkStopConditions();
        }

        $this->closeQuietly($connection);

        $this->info("Stopped. Processed {$this->processed} message(s).");
    }

    /**
     * Declare main queue + (optionally) its dead-letter queue, and bind both.
     */
    private function declareQueues(
        AMQPChannel $channel,
        string $service,
        string $type,
        string $exchange,
        bool $dlqEnabled,
        string $dlxName,
    ): void {
        $queue = "$service.$type";
        $args = [];

        if ($dlqEnabled) {
            $dlq = "$queue.dlq";

            $channel->queue_declare($dlq, durable: true, auto_delete: false);
            $channel->queue_bind($dlq, $dlxName, $type);

            $args = new AMQPTable(['x-dead-letter-exchange' => $dlxName]);
        }

        $channel->queue_declare($queue, durable: true, auto_delete: false, arguments: $args);
        $channel->queue_bind($queue, $exchange, $type);
    }

    /**
     * @return array<string, class-string<MessageHandler>>
     */
    private function discoverHandlers(HandlerDiscovery $discovery): array
    {
        $map = [];

        foreach ($discovery->discover() as $class) {
            foreach ((array) $class::type() as $type) {
                if (isset($map[$type]) && $map[$type] !== $class) {
                    $this->warn("Duplicate handler for '$type': {$map[$type]} replaced by $class.");
                }

                $map[$type] = $class;
            }
        }

        return $map;
    }

    private function dispatch(AMQPMessage $message, string $class, Listener $listener, LoggerInterface $logger, bool $dlqEnabled): void
    {
        $this->processed++;

        $routingKey = (string) $message->getRoutingKey();

        try {
            $envelope = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $logger->warning('ListenCommand: malformed JSON, rejecting message', [
                'error' => $e->getMessage(),
                'body' => substr($message->getBody(), 0, 256),
            ]);
            $this->writeLine('FAIL', '(malformed JSON)', "from $routingKey", 'error');
            $message->nack();

            return;
        }

        if (! is_array($envelope)) {
            $logger->warning('ListenCommand: envelope is not an array, rejecting');
            $this->writeLine('FAIL', '(non-array envelope)', "from $routingKey", 'error');
            $message->nack();

            return;
        }

        $type = (string) ($envelope['type'] ?? $routingKey);
        $service = (string) ($envelope['service'] ?? 'unknown');
        $mode = is_subclass_of($class, ShouldQueue::class) ? 'queued' : 'sync';

        $this->writeLine('RECV', $type, "from $service");

        $start = hrtime(true);

        try {
            $success = $listener->handle($class, $envelope);
        } catch (Throwable $e) {
            $logger->error('ListenCommand: handler threw', [
                'handler' => $class,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            $success = false;
        }

        $elapsed = intdiv(hrtime(true) - $start, 1_000_000);

        if ($success) {
            $message->ack();
            $this->writeLine('DONE', $type, "$mode → $class ({$elapsed}ms)", 'info');
        } else {
            $message->nack();
            $this->writeLine('FAIL', $type, $dlqEnabled ? "$class → DLQ" : "$class — discarded (DLQ off)", 'error');
        }
    }

    private function checkStopConditions(): void
    {
        if ($this->shouldStop) {
            return;
        }

        if ($this->maxJobs > 0 && $this->processed >= $this->maxJobs) {
            $this->info("Reached --max-jobs={$this->maxJobs}, stopping.");
            $this->shouldStop = true;

            return;
        }

        if ($this->memoryLimit > 0 && (memory_get_usage(true) / 1024 / 1024) >= $this->memoryLimit) {
            $this->warn("Memory limit {$this->memoryLimit}MB reached, stopping.");
            $this->shouldStop = true;
        }
    }

    private function closeQuietly(Connection $connection): void
    {
        try {
            $connection->close();
        } catch (Throwable) {
            // Connection may already be gone — nothing useful to do.
        }
    }

    private function writeLine(string $tag, string $type, string $detail, string $tone = 'comment'): void
    {
        $this->line(sprintf('<fg=gray>[%s]</> <%s>%s</> %s <fg=gray>%s</>', now()->format('Y-m-d H:i:s'), $tone, $tag, $type, $detail));
    }
}
