<?php

declare(strict_types=1);

namespace Jurager\Microservice\Queue;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;

/**
 * Touches a file from queue:work so a liveness probe can spot a stuck worker.
 *
 * Laravel's --timeout is a signal handler, and PHP runs it only between PHP
 * operations. A job stuck inside a C call, such as a query waiting on a lock,
 * is never stopped by it.
 */
final class WorkerHeartbeat
{
    private int $beatAt = 0;

    public function __construct(private readonly string $path) {}

    /**
     * Touch the file before each poll of the queue and when a job starts.
     */
    public function subscribe(Dispatcher $events): void
    {
        // Looping is dispatched with until(): returning false would pause the worker.
        $events->listen([Looping::class, JobProcessing::class], function (): void {
            $this->beat();
        });
    }

    /**
     * Touch the file, at most once per second.
     */
    public function beat(): void
    {
        if (time() === $this->beatAt) {
            return;
        }

        touch($this->path);

        $this->beatAt = time();
    }
}
