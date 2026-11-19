<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Jurager\Microservice\Tests\TestCase;

/**
 * Leave queue workers untouched unless a heartbeat file is configured.
 */
class WorkerHeartbeatDisabledTest extends TestCase
{
    public function test_registers_no_listener_without_a_path(): void
    {
        $this->assertNull(config('microservice.queue.heartbeat'));
        $this->assertFalse(Event::hasListeners(Looping::class));
    }
}
