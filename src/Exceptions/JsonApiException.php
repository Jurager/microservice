<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * An HTTP error rendered with the given JSON:API error `meta`.
 */
class JsonApiException extends HttpException
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public function __construct(
        int $status,
        string $message = '',
        public readonly array $meta = [],
        array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($status, $message, $previous, $headers);
    }
}
