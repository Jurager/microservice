<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidRequestIdException extends HttpException
{
    public function __construct(string $message = 'X-Request-Id must be a valid UUID v4.')
    {
        parent::__construct(400, $message);
    }
}
