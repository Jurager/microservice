<?php

declare(strict_types=1);

namespace Jurager\Microservice\Events;

use Illuminate\Foundation\Events\Dispatchable;

class IdempotentRequestDetected
{
    use Dispatchable;

    public function __construct(
        public readonly string $requestId,
        public readonly string $method,
        public readonly string $path,
        public readonly int $cachedStatusCode,
    ) {
    }
}
