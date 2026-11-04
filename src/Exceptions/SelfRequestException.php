<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use LogicException;

class SelfRequestException extends LogicException
{
    public function __construct(public readonly string $service)
    {
        parent::__construct("HTTP requests to the current service [$service] are not allowed. Call a local service or repository instead.");
    }
}
