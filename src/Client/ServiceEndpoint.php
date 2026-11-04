<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

/**
 * Where a service answers, and how long a request to it may take by default.
 */
final readonly class ServiceEndpoint
{
    public function __construct(
        public string $baseUrl,
        public int $timeout,
    ) {
    }
}
