<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use JsonException;
use Jurager\Microservice\Bus\MessageBus;

#[Signature('microservice:emit
             {type    : Event type (e.g. "sfm.site.updated")}
             {payload : JSON-encoded payload (e.g. \'{"site_id":1}\')}')]
#[Description('Manually publish an event to the bus (for testing).')]
class EmitCommand extends Command implements PromptsForMissingInput
{
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'type'    => ['What event type would you like to emit?', 'e.g. sfm.site.updated'],
            'payload' => ['What payload should be sent?', 'e.g. {"site_id":1}'],
        ];
    }

    public function handle(MessageBus $bus): void
    {
        $type = (string) $this->input('type');
        $raw = (string) $this->input('payload');

        try {
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->fail("Invalid JSON payload: {$e->getMessage()}");
        }

        if (! is_array($payload)) {
            $this->fail('Payload must be a JSON object/array.');
        }

        $bus->publish($type, $payload);

        $this->components->info("Published event [$type].");
    }
}
