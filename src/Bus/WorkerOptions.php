<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

/**
 * Limits and housekeeping of a bus worker run — the options of microservice:listen.
 */
final readonly class WorkerOptions
{
    /**
     * @param  int  $memory  Megabytes of memory after which the worker stops; zero for no limit.
     * @param  int  $maxJobs  Messages after which the worker stops; zero for no limit.
     * @param  int  $maxReconnects  Reconnect attempts before giving up; zero to retry forever.
     * @param  int  $backoff  Longest wait between reconnect attempts, in seconds.
     * @param  string|null  $heartbeat  File whose modification time is refreshed while the worker runs.
     * @param  int  $rescan  Seconds between checks of which types the handlers consume; zero disables them.
     */
    public function __construct(
        public int $memory = 128,
        public int $maxJobs = 0,
        public int $maxReconnects = 10,
        public int $backoff = 30,
        public ?string $heartbeat = null,
        public int $rescan = 30,
    ) {
    }
}
