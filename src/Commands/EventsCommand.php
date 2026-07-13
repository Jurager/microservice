<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Jurager\Microservice\Bus\HandlerDiscovery;

#[Signature('microservice:events')]
#[Description('List discovered inter-service event handlers.')]
class EventsCommand extends Command
{
    public function handle(HandlerDiscovery $discovery): void
    {
        $handlers = $discovery->discover();

        if ($handlers === []) {
            $this->components->warn('No MessageHandler implementations found.');

            return;
        }

        $service = (string) config('microservice.name', 'app');

        // type => list of handlers, in discovery order
        // mirrors ListenCommand, where the last discovered handler for a type wins.
        $map = [];

        foreach ($handlers as $handler) {
            foreach ((array) $handler::type() as $type) {
                $map[$type][] = $handler;
            }
        }

        ksort($map);

        $rows = [];
        $conflicts = [];

        foreach ($map as $type => $classes) {
            $active = end($classes);

            foreach ($classes as $class) {
                $shadowed = $class !== $active;

                if ($shadowed) {
                    $conflicts[] = $type;
                }

                $rows[] = [
                    $type,
                    "$service.$type",
                    $class.($shadowed ? ' <fg=yellow>(shadowed)</>' : ''),
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

        if ($conflicts !== []) {
            $this->components->warn(sprintf(
                'Multiple handlers registered for: %s. Only the last discovered handler will receive messages.',
                implode(', ', array_unique($conflicts)),
            ));
        }
    }
}