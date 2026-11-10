<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Worker as QueueWorker;
use Illuminate\Support\Facades\Facade;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\Contracts\WorkerOutput;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPBasicCancelException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPDataReadException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPIOWaitException;
use PhpAmqpLib\Exception\AMQPSocketException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Consume events, reconnecting as needed until stopped or a limit is reached.
 */
class Worker
{
    private bool $shouldStop = false;

    private int $processed = 0;

    private int $reconnects = 0;

    private int $beatAt = 0;

    private int $rescanAt = 0;

    private WorkerOptions $options;

    private WorkerOutput $output;

    /**
     * The last `queue:restart` signal when the run began.
     */
    private mixed $restartedAt = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Listener $listener,
        private readonly Topology $topology,
        private readonly LoggerInterface $logger,
        private readonly Container $container,
        private readonly Cache $cache,
    ) {
        $this->options = new WorkerOptions;
        $this->output = new NullWorkerOutput;
    }

    /**
     * Consume the event types of the given handlers until stop() is called or a limit is reached.
     *
     * @param  list<class-string<MessageHandler>>  $classes
     *
     * @throws WorkerFailedException
     */
    public function run(array $classes, WorkerOptions $options, ?WorkerOutput $output = null): void
    {
        $this->options = $options;
        $this->output = $output ?? new NullWorkerOutput;
        $this->shouldStop = false;
        $this->restartedAt = $this->lastRestart();

        $handlers = $this->subscriptions($classes);

        while (! $this->shouldStop) {
            try {
                // Carries forward what rescanning picked up, so a reconnect resubscribes at once.
                $handlers = $this->consume($classes, $handlers);

                break;
            } catch (AMQPBasicCancelException|AMQPConnectionClosedException|AMQPDataReadException|AMQPSocketException|AMQPIOException|AMQPIOWaitException $e) {
                $this->closeConnection();

                if ($this->shouldStop) {
                    break;
                }

                $this->reconnect($e);
            } catch (Throwable $e) {
                $this->logger->error('Bus worker: AMQP loop error', ['error' => $e->getMessage(), 'exception' => $e]);
                $this->closeConnection();

                throw new WorkerFailedException($e->getMessage(), previous: $e);
            }
        }

        $this->closeConnection();
    }

    /**
     * Ask the worker to stop after the message it is handling.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Messages received during the last run.
     */
    public function processed(): int
    {
        return $this->processed;
    }

    /**
     * Which handler consumes each event type; a later handler replaces an earlier one.
     *
     * @param  list<class-string<MessageHandler>>  $classes
     * @return array<string, class-string<MessageHandler>>
     */
    public function subscriptions(array $classes): array
    {
        $map = [];

        foreach (HandlerDiscovery::byType($classes) as $type => $handlers) {
            $map[$type] = end($handlers);

            foreach (array_slice($handlers, 0, -1) as $shadowed) {
                $this->output->warn("Duplicate handler for '$type': $shadowed replaced by {$map[$type]}.");
            }
        }

        return $map;
    }

    /**
     * @param  list<class-string<MessageHandler>>  $classes
     * @param  array<string, class-string<MessageHandler>>  $handlers
     * @return array<string, class-string<MessageHandler>> The subscriptions as they stood when the loop ended.
     */
    private function consume(array $classes, array $handlers): array
    {
        $channel = $this->connection->channel();

        $this->topology->declareExchanges($channel);

        $channel->basic_qos(prefetch_size: 0, prefetch_count: 1, a_global: false);

        foreach ($handlers as $type => $class) {
            $this->subscribe($channel, $type, $class);
        }

        // The outage is over, so earlier attempts no longer count.
        $this->reconnects = 0;

        $this->announce($handlers);

        // The first beat marks the worker alive before the first wait window.
        $this->heartbeat();
        $this->rescanAt = time();

        while (! $this->shouldStop && ($channel->is_consuming() || $this->options->rescan > 0)) {
            try {
                $channel->wait(null, false, 1);
            } catch (AMQPTimeoutException) {
                // No message in this window — check the limits and signals, then wait again.
            }

            // Memory can grow while idle too, so the limits are checked between windows as well.
            $this->checkLimits();
            $this->heartbeat();

            $handlers = $this->rescan($channel, $classes, $handlers);
        }

        return $handlers;
    }

    private function subscribe(AMQPChannel $channel, string $type, string $class): void
    {
        $this->reportDeclaration($type, $this->topology->declareQueue($channel, $type));

        $channel->basic_consume(
            $this->topology->queue($type),
            consumer_tag: $this->topology->consumerTag($type),
            callback: function (AMQPMessage $message) use ($class): void {
                $this->handle($message, $class);
                $this->checkLimits();
            },
        );
    }

    private function reportDeclaration(string $type, QueueDeclaration $declaration): void
    {
        $queue = $this->topology->queue($type);

        if ($declaration === QueueDeclaration::Recreated) {
            $this->logger->warning('Bus worker: recreated a queue declared with other arguments', ['queue' => $queue]);
            $this->output->warn("Recreated queue $queue: it was declared with other arguments.");
        }

        if ($declaration === QueueDeclaration::Outdated) {
            $this->logger->warning('Bus worker: consuming a queue declared with other arguments', ['queue' => $queue]);
            $this->output->warn("Queue $queue was declared with other arguments and still holds messages; consuming it as it is until a start finds it empty.");
        }
    }

    /**
     * Refresh changed subscriptions, preserving queues for inactive event types.
     *
     * @param  list<class-string<MessageHandler>>  $classes
     * @param  array<string, class-string<MessageHandler>>  $handlers
     * @return array<string, class-string<MessageHandler>>
     */
    private function rescan(AMQPChannel $channel, array $classes, array $handlers): array
    {
        if ($this->options->rescan <= 0 || time() - $this->rescanAt < $this->options->rescan) {
            return $handlers;
        }

        $this->rescanAt = time();

        $fresh = $this->subscriptions($classes);
        $removed = array_diff_assoc($handlers, $fresh);
        $added = array_diff_assoc($fresh, $handlers);

        foreach (array_keys($removed) as $type) {
            $channel->basic_cancel($this->topology->consumerTag($type));
        }

        foreach ($added as $type => $class) {
            $this->subscribe($channel, $type, $class);
        }

        if ($removed !== []) {
            $this->output->info('No longer listening for: '.implode(', ', array_keys($removed)));
        }

        if ($added !== []) {
            $this->output->info('Now also listening for: '.implode(', ', array_keys($added)));
        }

        return array_diff_key($handlers, $removed) + $added;
    }

    private function handle(AMQPMessage $message, string $class): void
    {
        $this->processed++;

        $envelope = $this->envelope($message);

        if ($envelope === null) {
            $message->nack();

            return;
        }

        $type = (string) ($envelope['type'] ?? $message->getRoutingKey());

        $this->output->message('RECV', $type, 'from '.($envelope['service'] ?? 'unknown'));

        $started = hrtime(true);

        try {
            $handled = $this->listener->handle($class, $envelope);
        } catch (Throwable $e) {
            $this->logger->error('Bus worker: handler threw', ['handler' => $class, 'type' => $type, 'error' => $e->getMessage(), 'exception' => $e]);
            $handled = false;
        }

        $elapsed = intdiv(hrtime(true) - $started, 1_000_000);

        if ($handled) {
            $message->ack();
            $this->output->message('DONE', $type, (is_subclass_of($class, ShouldQueue::class) ? 'queued' : 'sync')." → $class ({$elapsed}ms)", 'info');
        } else {
            $message->nack();
            $this->output->message('FAIL', $type, $this->topology->deadLettering() ? "$class → DLQ" : "$class — discarded (DLQ off)", 'error');
        }

        $this->resetScope();
    }

    /**
     * Reset request and job state before handling the next message.
     */
    private function resetScope(): void
    {
        $log = $this->container->make('log');

        if (method_exists($log, 'flushSharedContext')) {
            $log->flushSharedContext();
        }

        if (method_exists($log, 'withoutContext')) {
            $log->withoutContext();
        }

        // Query-duration handlers fire again and sticky writes are forgotten, message by message.
        foreach ($this->container->make('db')->getConnections() as $connection) {
            $connection->resetTotalQueryDuration();
            $connection->allowQueryDurationHandlersToRunAgain();

            if (QueueWorker::$flushStickyConnections) {
                $connection->forgetRecordModificationState();
            }
        }

        $this->container->forgetScopedInstances();

        Facade::clearResolvedInstances();
    }

    /**
     * The decoded envelope of a message, or null for one that cannot be one.
     */
    private function envelope(AMQPMessage $message): ?array
    {
        try {
            $envelope = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->logger->warning('Bus worker: malformed JSON, rejecting message', ['error' => $e->getMessage(), 'body' => substr($message->getBody(), 0, 256)]);
            $this->output->message('FAIL', '(malformed JSON)', 'from '.$message->getRoutingKey(), 'error');

            return null;
        }

        if (! is_array($envelope)) {
            $this->logger->warning('Bus worker: envelope is not an array, rejecting');
            $this->output->message('FAIL', '(non-array envelope)', 'from '.$message->getRoutingKey(), 'error');

            return null;
        }

        return $envelope;
    }

    /** @param  array<string, class-string<MessageHandler>>  $handlers */
    private function announce(array $handlers): void
    {
        $deadLetters = $this->topology->deadLettering() ? " (DLQ → {$this->topology->deadLetterExchange})" : '';

        if ($handlers !== []) {
            $this->output->info('Listening for events: '.implode(', ', array_keys($handlers)).$deadLetters);

            return;
        }

        $rescan = $this->options->rescan > 0 ? " (rescanning every {$this->options->rescan}s)" : '';

        $this->output->info("No event types active yet — waiting$rescan$deadLetters");
    }

    /** @throws WorkerFailedException once the reconnect attempts are used up */
    private function reconnect(Throwable $e): void
    {
        $this->reconnects++;

        if ($this->options->maxReconnects > 0 && $this->reconnects > $this->options->maxReconnects) {
            $this->logger->error('Bus worker: giving up after repeated connection failures', ['attempts' => $this->reconnects, 'error' => $e->getMessage()]);

            throw new WorkerFailedException("Connection lost, gave up after {$this->reconnects} attempts: {$e->getMessage()}", previous: $e);
        }

        $delay = max(1, min(2 ** $this->reconnects, $this->options->backoff));

        $this->logger->warning('Bus worker: connection lost, reconnecting', ['attempt' => $this->reconnects, 'delay' => $delay, 'error' => $e->getMessage()]);
        $this->output->warn("Connection lost, reconnecting in {$delay}s (attempt {$this->reconnects})");

        // Wait a second at a time, so a stop signal is not held up by the whole delay.
        for ($until = time() + $delay; time() < $until && ! $this->shouldStop;) {
            sleep(1);
            $this->heartbeat();
        }
    }

    private function checkLimits(): void
    {
        if ($this->shouldStop) {
            return;
        }

        if ($this->options->maxJobs > 0 && $this->processed >= $this->options->maxJobs) {
            $this->output->info("Reached --max-jobs={$this->options->maxJobs}, stopping.");
            $this->shouldStop = true;

            return;
        }

        if ($this->options->memory > 0 && memory_get_usage(true) / 1024 / 1024 >= $this->options->memory) {
            $this->output->warn("Memory limit {$this->options->memory}MB reached, stopping.");
            $this->shouldStop = true;

            return;
        }

        // Determine if a queue restart was requested.
        if ($this->lastRestart() !== $this->restartedAt) {
            $this->output->info('Restart signal received, stopping.');
            $this->shouldStop = true;
        }
    }

    /**
     * When `queue:restart` last ran, as Laravel's queue workers read it.
     */
    private function lastRestart(): mixed
    {
        try {
            return $this->cache->get('illuminate:queue:restart');
        } catch (Throwable) {
            return $this->restartedAt;
        }
    }

    /**
     * Update the heartbeat file at most once per second.
     */
    private function heartbeat(): void
    {
        if ($this->options->heartbeat === null || time() === $this->beatAt) {
            return;
        }

        touch($this->options->heartbeat);

        $this->beatAt = time();
    }

    private function closeConnection(): void
    {
        try {
            $this->connection->close();
        } catch (Throwable) {
            // Already gone — nothing to do.
        }
    }
}
