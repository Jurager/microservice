<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use RuntimeException;

class ServiceRequestException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly ?array $errors = null,
    ) {
        parent::__construct($message ?: "Service request failed with status {$status}.");
    }
}
