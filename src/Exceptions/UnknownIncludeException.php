<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class UnknownIncludeException extends HttpException
{
    public function __construct(string $path)
    {
        parent::__construct(400, "Unknown include: \"$path\".");
    }
}
