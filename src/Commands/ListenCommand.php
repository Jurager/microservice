<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Jurager\Microservice\Bus\Contracts\WorkerOutput;
use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Bus\MessageBus;
use Jurager\Microservice\Bus\Worker;
use Jurager\Microservice\Bus\WorkerFailedException;
use Jurager\Microservice\Bus\WorkerOptions;
use Jurager\Microservice\Signing\Signer;
use Throwable;

#[Signature('microservice:listen
             {--memory=128 : Memory limit in MB; the worker stops when exceeded}
             {--max-jobs=0 : Stop after this many messages (0 = unlimited)}
             {--max-reconnects=10 : Reconnect attempts before exiting (0 = unlimited)}
             {--backoff=30 : Longest wait between reconnect attempts, in seconds}
             {--heartbeat= : Path to a file whose mtime is refreshed while the loop runs}
             {--rescan=30 : Seconds between checks for handlers whose type() list changed (0 = disable)}')]
#[Description('Listen for inter-service events from RabbitMQ.')]
class ListenCommand extends Command
{
    public function handle(MessageBus $bus, HandlerDiscovery $discovery, Worker $worker, Signer $signer): void
    {
        if (! $bus->enabled()) {
            $this->fail('MessageBus is disabled (config: microservice.bus.enabled).');
        }

        if ($signer->enabled()) {
            try {
                $signer->assertConfigured();
            } catch (Throwable $e) {
                // Nothing could be verified, so refuse to start rather than reject every message.
                $this->fail($e->getMessage());
            }
        }

        $classes = $discovery->discover();

        if ($classes === []) {
            $this->warn('No message handler classes discovered — nothing to listen for.');

            return;
        }

        $options = $this->workerOptions();

        if ($options->rescan <= 0 && $worker->subscriptions($classes) === []) {
            $this->warn('No handlers currently report any event type, and --rescan is disabled — nothing to listen for.');

            return;
        }

        $this->trap([SIGTERM, SIGINT], $worker->stop(...));

        try {
            $worker->run($classes, $options, $this->output());
        } catch (WorkerFailedException $e) {
            $this->fail($e->getMessage());
        }

        $this->info("Stopped. Processed {$worker->processed()} message(s).");
    }

    /**
     * What the worker reports, written to the console.
     */
    private function output(): WorkerOutput
    {
        return new class($this) implements WorkerOutput
        {
            public function __construct(private readonly Command $command)
            {
            }

            public function info(string $message): void
            {
                $this->command->info($message);
            }

            public function warn(string $message): void
            {
                $this->command->warn($message);
            }

            public function message(string $step, string $type, string $detail, string $outcome = 'comment'): void
            {
                $this->command->line(sprintf('<fg=gray>[%s]</> <%s>%s</> %s <fg=gray>%s</>', now()->format('Y-m-d H:i:s'), $outcome, $step, $type, $detail));
            }
        };
    }

    private function workerOptions(): WorkerOptions
    {
        return new WorkerOptions(
            memory: (int) $this->option('memory'),
            maxJobs: (int) $this->option('max-jobs'),
            maxReconnects: (int) $this->option('max-reconnects'),
            backoff: (int) $this->option('backoff'),
            heartbeat: ((string) $this->option('heartbeat')) ?: null,
            rescan: max(0, (int) $this->option('rescan')),
        );
    }
}
