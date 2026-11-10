<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

/**
 * How declaring an event type's queue went.
 */
enum QueueDeclaration
{
    /** The queue matches the configured arguments. */
    case Declared;

    /** The queue had other arguments and was empty, so it was recreated. */
    case Recreated;

    /** The queue has other arguments but still holds messages, so it is kept as it is. */
    case Outdated;
}
