<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Jurager\Microservice\Bus\Contracts\WorkerOutput;

/**
 * Discards what a worker reports.
 */
final class NullWorkerOutput implements WorkerOutput
{
    public function info(string $message): void
    {
    }

    public function warn(string $message): void
    {
    }

    public function message(string $step, string $type, string $detail, string $outcome = 'comment'): void
    {
    }
}
