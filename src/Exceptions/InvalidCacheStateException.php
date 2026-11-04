<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidCacheStateException extends HttpException
{
    public function __construct(string $message = 'Invalid cache state.')
    {
        parent::__construct(500, $message);
    }
}
