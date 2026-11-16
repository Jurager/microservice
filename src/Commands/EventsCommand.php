<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Bus\Topology;

#[Signature('microservice:events')]
#[Description('List discovered inter-service event handlers.')]
class EventsCommand extends Command
{
    public function handle(HandlerDiscovery $discovery, Topology $topology): void
    {
        $handlers = $discovery->discover();

        if ($handlers === []) {
            $this->components->warn('No MessageHandler implementations found.');

            return;
        }

        $map = HandlerDiscovery::byType($handlers);

        ksort($map);

        $rows = [];

        foreach ($map as $type => $classes) {
            foreach ($classes as $class) {
                $rows[] = [
                    $type,
                    $topology->queue($type),
                    $class,
                    is_subclass_of($class, ShouldQueue::class) ? 'queued' : 'sync',
                ];
            }
        }

        $this->table(['Type', 'Queue', 'Handler', 'Mode'], $rows);

        $this->newLine();

        $this->components->info(sprintf(
            'Discovered %d handler%s covering %d event type%s.',
            count($handlers),
            count($handlers) === 1 ? '' : 's',
            count($map),
            count($map) === 1 ? '' : 's',
        ));
    }
}
