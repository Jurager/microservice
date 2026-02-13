<?php

declare(strict_types=1);

namespace Jurager\Microservice\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ServiceHealthChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $service,
        public readonly string $url,
        public readonly bool $isHealthy,
        public readonly int $failureCount,
        public readonly ?string $previousStatus = null,
    ) {
    }
}
