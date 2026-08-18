<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ServiceRequestException extends HttpException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly ?array $errors = null,
    ) {
        parent::__construct($status, $message ?: "Service request failed with status $status.");
    }
}
