<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use RuntimeException;

class ServiceUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $service,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?: "Service [$service] is unavailable: all instances failed.",
            0,
            $previous,
        );
    }
}
