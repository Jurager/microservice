<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class MissingSignatureException extends HttpException
{
    public function __construct(string $message = 'Missing signature headers.')
    {
        parent::__construct(401, $message);
    }
}
