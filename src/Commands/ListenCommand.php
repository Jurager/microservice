<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Command;
use Jurager\Microservice\Bus\Contracts\MessageHandler;

/**
 * Wrapper around `rabbitevents:listen` that auto-discovers event types
 * from the application's `config/messages.php` handler map.
 *
 * Usage:
 *   php artisan microservice:listen                  — все типы из config/messages.php
 *   php artisan microservice:listen sfm.site.updated — только указанные
 */
class ListenCommand extends Command
{
    protected $signature = 'microservice:listen
                            {types?* : Limit to specific event types (defaults to all registered handlers)}
                            {--memory=128 : The memory limit in megabytes}
                            {--timeout=60 : The number of seconds a child process can run}
                            {--tries=1 : Number of times to attempt a job before logging it failed}';

    protected $description = 'Listen for inter-service events from RabbitMQ (auto-discovers types from config/messages.php).';

    public function handle(): int
    {
        $types = $this->argument('types') ?: $this->discoverTypes();

        if (empty($types)) {
            $this->warn('No message handlers registered in config/messages.php — nothing to listen for.');

            return self::SUCCESS;
        }

        $this->info('Listening for events: ' . implode(', ', $types));

        return $this->call('rabbitevents:listen', [
            'event'     => $types,
            '--memory'  => $this->option('memory'),
            '--timeout' => $this->option('timeout'),
            '--tries'   => $this->option('tries'),
        ]);
    }

    /**
     * @return list<string>
     */
    private function discoverTypes(): array
    {
        return collect(config('messages', []))
            ->filter(fn (string $class) => is_subclass_of($class, MessageHandler::class))
            ->map(fn (string $class) => $class::type())
            ->unique()
            ->values()
            ->all();
    }
}
