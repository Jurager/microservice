<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use Jurager\Microservice\Client\ServiceResponse;
use RuntimeException;

class ServiceRequestException extends RuntimeException
{
    public function __construct(
        public readonly ServiceResponse $response,
        string $message = '',
    ) {
        parent::__construct(
            $message ?: "Service request failed with status [{$response->status()}]."
        );
    }
}
