<?php

declare(strict_types=1);

namespace Jurager\Microservice\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RoutesRegistered
{
    use Dispatchable;

    public function __construct(
        public readonly string $service,
        public readonly array $routes,
        public readonly ?string $gateway = null,
    ) {
    }
}
