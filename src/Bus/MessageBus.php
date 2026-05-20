<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class MessageBus
{
    public function __construct(private readonly string $connection) {}

    public function publish(string $type, array $payload, string $queue): void
    {
        try {
            Queue::connection($this->connection)->pushRaw(
                json_encode(['type' => $type, 'payload' => $payload]),
                $queue,
            );
        } catch (\Throwable $e) {
            Log::error('MessageBus: failed to publish', [
                'type'  => $type,
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
