<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Jurager\Microservice\Bus\Connection;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Bus\Listener;
use Jurager\Microservice\Bus\MessageBus;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Consume events from RabbitMQ and dispatch to handlers registered in
 * config/messages.php. For each handler type a durable queue is declared,
 * bound to the topic exchange by routing key = type.
 *
 * When DLQ is enabled (config: microservice.bus.dead_letter.enabled), failed
 * messages (bad signature, malformed JSON, handler exception) are nacked and
 * routed via the DLX into a per-handler dead-letter queue for inspection.
 *
 * Usage:
 *   php artisan microservice:listen
 *   php artisan microservice:listen --memory=256 --max-jobs=1000
 *
 * Graceful shutdown: SIGTERM/SIGINT stops the loop after the current message.
 */
class ListenCommand extends Command
{
    protected $signature = 'microservice:listen
                            {--memory=128 : Memory limit in MB; the worker stops when exceeded}
                            {--max-jobs=0 : Stop after this many messages (0 = unlimited)}';

    protected $description = 'Listen for inter-service events from RabbitMQ.';

    private bool $shouldStop = false;

    private int $processed = 0;

    /**
     * @throws Exception
     */
    public function handle(MessageBus $bus, Connection $connection, Listener $listener, HandlerDiscovery $discovery): int
    {
        if (! $bus->enabled()) {
            $this->warn('MessageBus is disabled (config: microservice.bus.enabled). Refusing to start.');

            return self::FAILURE;
        }

        $handlers = $this->discoverHandlers($discovery);

        if (empty($handlers)) {
            $this->warn('No MessageHandler implementations found — nothing to listen for.');

            return self::SUCCESS;
        }

        $this->installSignalHandlers();

        $channel  = $connection->channel();
        $exchange = $connection->exchange();
        $service  = (string) config('microservice.name', 'app');
        $memory   = (int) $this->option('memory');
        $maxJobs  = (int) $this->option('max-jobs');

        $dlqEnabled = (bool) config('microservice.bus.dead_letter.enabled', true);
        $dlxName    = (string) config('microservice.bus.dead_letter.exchange', 'events.dlx');

        if ($dlqEnabled) {
            $channel->exchange_declare($dlxName, 'topic', durable: true, auto_delete: false);
        }

        // Fair dispatch: don't pile messages on a single worker
        $channel->basic_qos(prefetch_size: 0, prefetch_count: 1, a_global: false);

        foreach ($handlers as $type => $class) {
            $this->declareQueues($channel, $service, $type, $exchange, $dlqEnabled, $dlxName);

            $queue = "$service.$type";

            $channel->basic_consume(
                $queue,
                consumer_tag: "$service-$type",
                callback: function (AMQPMessage $message) use ($class, $listener, $memory, $maxJobs, $dlqEnabled): void {
                    $this->dispatch($message, $class, $listener, $dlqEnabled);

                    if ($maxJobs > 0 && $this->processed >= $maxJobs) {
                        $this->info("Reached --max-jobs=$maxJobs, stopping.");
                        $this->shouldStop = true;
                    }

                    if ($memory > 0 && (memory_get_usage(true) / 1024 / 1024) >= $memory) {
                        $this->warn("Memory limit {$memory}MB reached, stopping.");
                        $this->shouldStop = true;
                    }
                },
            );
        }

        $this->info('Listening for events: ' . implode(', ', array_keys($handlers))
            . ($dlqEnabled ? " (DLQ → $dlxName)" : ''));

        while (! $this->shouldStop && $channel->is_consuming()) {
            try {
                $channel->wait(null, false, 1);
            } catch (AMQPTimeoutException) {
                // No message in window — normal, loop and check signals
            } catch (Throwable $e) {
                Log::error('ListenCommand: AMQP loop error', ['error' => $e->getMessage()]);
                $this->error('AMQP error: ' . $e->getMessage());

                return self::FAILURE;
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $connection->close();

        $this->info("Stopped. Processed $this->processed message(s).");

        return self::SUCCESS;
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
        $args  = [];

        if ($dlqEnabled) {
            $dlq = "$queue.dlq";

            $channel->queue_declare($dlq, durable: true, auto_delete: false);
            $channel->queue_bind($dlq, $dlxName, $type);

            $args = new AMQPTable(['x-dead-letter-exchange' => $dlxName]);
        }

        $channel->queue_declare(
            $queue,
            durable: true,
            auto_delete: false,
            arguments: $args,
        );
        $channel->queue_bind($queue, $exchange, $type);
    }

    /**
     * @return array<string, class-string<MessageHandler>>
     */
    private function discoverHandlers(HandlerDiscovery $discovery): array
    {
        $map = [];

        foreach ($discovery->discover() as $class) {
            $map[$class::type()] = $class;
        }

        return $map;
    }

    private function dispatch(AMQPMessage $message, string $class, Listener $listener, bool $dlqEnabled): void
    {
        $this->processed++;

        $routingKey = (string) $message->getRoutingKey();

        try {
            $envelope = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::warning('ListenCommand: malformed JSON, rejecting message', [
                'error' => $e->getMessage(),
                'body'  => substr($message->getBody(), 0, 256),
            ]);
            $this->writeLine('FAIL', '(malformed JSON)', "from $routingKey", 'error');
            $this->reject($message, $dlqEnabled);

            return;
        }

        if (! is_array($envelope)) {

            Log::warning('ListenCommand: envelope is not an array, rejecting');

            $this->writeLine('FAIL', '(non-array envelope)', "from $routingKey", 'error');
            $this->reject($message, $dlqEnabled);

            return;
        }

        $type    = (string) ($envelope['type'] ?? $routingKey);
        $service = (string) ($envelope['service'] ?? 'unknown');
        $mode    = is_subclass_of($class, ShouldQueue::class) ? 'queued' : 'sync';

        $this->writeLine('RECV', $type, "from $service");

        $start   = microtime(true);
        $success = $listener->handle($class, $envelope);
        $elapsed = (int) ((microtime(true) - $start) * 1000);

        if ($success) {
            $message->ack();
            $this->writeLine('DONE', $type, "$mode → $class ({$elapsed}ms)", 'info');
        } else {
            $this->reject($message, $dlqEnabled);
            $this->writeLine(
                'FAIL',
                $type,
                $dlqEnabled ? "$class → DLQ" : "$class — discarded (DLQ off)",
                'error',
            );
        }
    }

    /**
     * Negative-ack with no requeue. When DLQ is configured, RabbitMQ routes
     * the message through the queue's x-dead-letter-exchange. Without DLQ,
     * the message is simply discarded.
     */
    private function reject(AMQPMessage $message, bool $dlqEnabled): void
    {
        if ($dlqEnabled) {
            $message->nack();
        } else {
            // No DLQ — ack to discard (avoid poison-message loops on infrastructure with no DLX configured)
            $message->ack();
        }
    }

    private function writeLine(string $tag, string $type, string $detail, string $tone = 'comment'): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $line      = "<fg=gray>[$timestamp]</> <$tone>$tag</> $type <fg=gray>$detail</>";

        $this->output->writeln($line);
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $stop = function (): void {
            $this->info('Signal received, stopping after current message...');
            $this->shouldStop = true;
        };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
}
