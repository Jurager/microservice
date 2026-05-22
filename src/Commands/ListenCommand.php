<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Bunny\Channel;
use Bunny\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Jurager\Microservice\Bus\Connection;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\Listener;
use Jurager\Microservice\Bus\MessageBus;
use Throwable;

/**
 * Consume events from RabbitMQ and dispatch to handlers registered in
 * config/messages.php. For each handler type a durable queue is declared,
 * bound to the topic exchange by routing key = type.
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

    public function handle(MessageBus $bus, Connection $connection, Listener $listener): int
    {
        if (! $bus->enabled()) {
            $this->warn('MessageBus is disabled (config: microservice.bus.enabled). Refusing to start.');

            return self::FAILURE;
        }

        $handlers = $this->discoverHandlers();

        if (empty($handlers)) {
            $this->warn('No message handlers registered in config/messages.php — nothing to listen for.');

            return self::SUCCESS;
        }

        $this->installSignalHandlers();

        $channel  = $connection->channel();
        $exchange = $connection->exchange();
        $service  = (string) config('microservice.name', 'app');
        $memory   = (int) $this->option('memory');
        $maxJobs  = (int) $this->option('max-jobs');

        foreach ($handlers as $type => $class) {
            $queue = "{$service}.{$type}";

            $channel->queueDeclare($queue, passive: false, durable: true, exclusive: false, autoDelete: false);
            $channel->queueBind($queue, $exchange, $type);

            $channel->consume(
                function (Message $message, Channel $ch) use ($class, $listener, $memory, $maxJobs): void {
                    $this->dispatch($message, $ch, $class, $listener);

                    if ($maxJobs > 0 && $this->processed >= $maxJobs) {
                        $this->info("Reached --max-jobs={$maxJobs}, stopping.");
                        $this->shouldStop = true;
                    }

                    if ($memory > 0 && (memory_get_usage(true) / 1024 / 1024) >= $memory) {
                        $this->warn("Memory limit {$memory}MB reached, stopping.");
                        $this->shouldStop = true;
                    }
                },
                $queue,
            );
        }

        $this->info('Listening for events: ' . implode(', ', array_keys($handlers)));

        while (! $this->shouldStop) {
            try {
                $connection->client()->run(1); // 1 second tick — lets us check signals
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
        $this->info("Stopped. Processed {$this->processed} message(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<string, class-string<MessageHandler>>
     */
    private function discoverHandlers(): array
    {
        $map = [];

        foreach ((array) config('messages', []) as $class) {
            if (! is_string($class) || ! is_subclass_of($class, MessageHandler::class)) {
                continue;
            }

            $map[$class::type()] = $class;
        }

        return $map;
    }

    private function dispatch(Message $message, Channel $channel, string $class, Listener $listener): void
    {
        $this->processed++;

        try {
            $envelope = json_decode($message->content, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::warning('ListenCommand: malformed JSON, dropping message', [
                'error' => $e->getMessage(),
                'body'  => substr((string) $message->content, 0, 256),
            ]);
            $channel->ack($message);

            return;
        }

        if (! is_array($envelope)) {
            Log::warning('ListenCommand: envelope is not an array, dropping');
            $channel->ack($message);

            return;
        }

        $success = $listener->handle($class, $envelope);

        // Always ack — failures are either invalid input (poison message, no point
        // requeueing) or already routed to Laravel queue (which owns retries).
        $channel->ack($message);

        if (! $success) {
            Log::debug('ListenCommand: message acked despite handler failure', [
                'class' => $class,
                'type'  => $envelope['type'] ?? null,
            ]);
        }
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
