<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use RuntimeException;

/**
 * The worker could not keep consuming: the broker stayed away, or the loop broke.
 */
class WorkerFailedException extends RuntimeException
{
}
