<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Jurager\Microservice\Bus\Contracts\MessageHandler;

/**
 * Show the message types registered in config/messages.php with their handler
 * classes and execution mode (queued vs sync). Useful for diagnosing
 * configuration before starting a listener.
 */
class EventsCommand extends Command
{
    protected $signature = 'microservice:events';

    protected $description = 'List inter-service event types registered in config/messages.php.';

    public function handle(): int
    {
        $handlers = (array) config('messages', []);

        if (empty($handlers)) {
            $this->warn('No message handlers registered in config/messages.php.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($handlers as $class) {
            if (! is_string($class) || ! is_subclass_of($class, MessageHandler::class)) {
                $rows[] = [(string) $class, '<error>not a MessageHandler</error>', '—'];

                continue;
            }

            $rows[] = [
                $class::type(),
                $class,
                is_subclass_of($class, ShouldQueue::class) ? 'queued' : 'sync',
            ];
        }

        $this->table(['Type', 'Handler', 'Mode'], $rows);

        return self::SUCCESS;
    }
}
