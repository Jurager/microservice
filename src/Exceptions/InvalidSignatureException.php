<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidSignatureException extends HttpException
{
    public function __construct(string $message = 'Invalid signature or timestamp.')
    {
        parent::__construct(401, $message);
    }
}
