<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Command;
use Jurager\Microservice\Bus\MessageBus;

/**
 * Manually publish an event to the bus for debugging.
 *
 * Example:
 *   php artisan microservice:emit sfm.site.updated '{"site_id":1,"domain":"example.com"}'
 *
 * The payload goes through MessageBus, so envelope/signature/logging are
 * identical to a real publish from application code.
 */
class EmitCommand extends Command
{
    protected $signature = 'microservice:emit
                            {type    : Event type (e.g. "sfm.site.updated")}
                            {payload : JSON-encoded payload (e.g. \'{"site_id":1}\')}';

    protected $description = 'Manually publish an event to the bus (for testing).';

    public function handle(MessageBus $bus): int
    {
        $type = (string) $this->argument('type');
        $raw  = (string) $this->argument('payload');

        try {
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error("Invalid JSON payload: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! is_array($payload)) {
            $this->error('Payload must be a JSON object/array.');

            return self::FAILURE;
        }

        $bus->publish($type, $payload);

        $this->info("Published event [$type].");

        return self::SUCCESS;
    }
}
