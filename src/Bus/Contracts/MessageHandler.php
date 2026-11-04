<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus\Contracts;

/**
 * Build handlers for synchronous or queued event processing.
 */
interface MessageHandler
{
    /**
     * The event type(s) this handler consumes, e.g. "sfm.site.updated".
     *
     * @return string|list<string>
     */
    public static function type(): string|array;

    /**
     * Build the handler for one message.
     */
    public static function from(array $payload, string $type = ''): static;
}
