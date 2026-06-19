<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus\Contracts;

interface MessageHandler
{
    public static function type(): string|array;

    public static function from(array $payload): static;
}
