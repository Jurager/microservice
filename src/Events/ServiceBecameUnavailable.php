<?php

declare(strict_types=1);

namespace Jurager\Microservice\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ServiceBecameUnavailable
{
    use Dispatchable;

    public function __construct(
        public readonly string $service,
        public readonly array $attemptedUrls,
        public readonly string $lastError,
    ) {
    }
}
