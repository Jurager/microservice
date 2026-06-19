<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Jurager\Microservice\Bus\HandlerDiscovery;

/**
 * Display discovered message handlers with their event type
 * and execution mode (sync or queued).
 */
class EventsCommand extends Command
{
    protected $signature = 'microservice:events';

    protected $description = 'List discovered inter-service event handlers.';

    public function handle(HandlerDiscovery $discovery): int
    {
        $handlers = $discovery->discover();

        if ($handlers === []) {
            $this->components->warn('No MessageHandler implementations found.');

            return self::SUCCESS;
        }

        $rows = array_map(
            static fn (string $handler): array => [
                'type'    => implode(', ', (array) $handler::type()),
                'handler' => $handler,
                'mode'    => is_subclass_of($handler, ShouldQueue::class)
                    ? 'queued'
                    : 'sync',
            ],
            $handlers,
        );

        usort($rows, static fn (array $a, array $b): int => [$a['type'], $a['handler']] <=> [$b['type'], $b['handler']]);

        $this->table(
            ['Type', 'Handler', 'Mode'],
            $rows,
        );

        $this->newLine();

        $this->components->info(sprintf('Discovered %d handler%s.', count($rows), count($rows) === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
