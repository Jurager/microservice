<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ServiceUnavailableException extends HttpException
{
    public function __construct(
        public readonly string $service,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            502,
            $message ?: "Service [$service] is unavailable: all instances failed.",
            $previous,
        );
    }
}
