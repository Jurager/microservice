<?php

declare(strict_types=1);

namespace Jurager\Microservice\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ManifestReceived
{
    use Dispatchable;

    public function __construct(
        public readonly string $service,
        public readonly array $manifest,
        public readonly int $routeCount,
    ) {
    }
}
