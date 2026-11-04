<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus\Contracts;

/**
 * Where a bus worker reports what it does — the console, for microservice:listen.
 */
interface WorkerOutput
{
    public function info(string $message): void;

    public function warn(string $message): void;

    /**
     * One step of handling a message.
     *
     * @param  string  $step  RECV, DONE or FAIL.
     * @param  string  $outcome  "info" for success, "error" for failure, "comment" otherwise.
     */
    public function message(string $step, string $type, string $detail, string $outcome = 'comment'): void;
}
