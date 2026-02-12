<?php

declare(strict_types=1);

namespace Jurager\Microservice\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ServiceRequestFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $service,
        public readonly string $url,
        public readonly string $method,
        public readonly string $path,
        public readonly int $statusCode,
        public readonly string $message,
    ) {
    }
}
