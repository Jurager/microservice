<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class MissingServiceNameException extends HttpException
{
    public function __construct(string $message = 'Missing service name header.')
    {
        parent::__construct(401, $message);
    }
}
