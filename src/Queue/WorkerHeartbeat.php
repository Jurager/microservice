<?php

declare(strict_types=1);

namespace Jurager\Microservice\Queue;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;

/**
 * Refreshes a heartbeat file from queue:work, so a liveness probe can tell a stuck
 * worker from a busy one. It covers what --timeout cannot: Laravel's timeout is a
 * SIGALRM handler, which never runs while the job is blocked inside a C call.
 */
final class WorkerHeartbeat
{
    private int $beatAt = 0;

    public function __construct(private readonly string $path) {}

    /**
     * Listen to the worker loop. Looping fires before every pop, idle or not,
     * and JobProcessing restarts the clock when a job begins.
     */
    public function subscribe(Dispatcher $events): void
    {
        // The worker reads Looping through until(): a listener returning false
        // would pause it, so this one must never return a value.
        $events->listen([Looping::class, JobProcessing::class], function (): void {
            $this->beat();
        });
    }

    /**
     * Update the heartbeat file at most once per second.
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
