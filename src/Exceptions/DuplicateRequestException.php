<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class DuplicateRequestException extends HttpException
{
    public function __construct(string $message = 'Request is already being processed.')
    {
        parent::__construct(409, $message);
    }
}
