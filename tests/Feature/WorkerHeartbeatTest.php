<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

/**
 * Refresh a heartbeat file from queue:work so a liveness probe can spot a stuck worker.
 */
class WorkerHeartbeatTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/microservice-worker-'.uniqid().'.heartbeat';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('microservice.queue.heartbeat', $this->path);
    }

    public function test_touches_the_file_on_every_worker_loop(): void
    {
        $this->assertFileDoesNotExist($this->path);

        Event::until(new Looping('redis', 'default'));

        $this->assertFileExists($this->path);
    }

    public function test_restarts_the_clock_when_a_job_begins(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([]);

        Event::dispatch(new JobProcessing('redis', $job));

        $this->assertFileExists($this->path);
    }

    public function test_never_pauses_the_worker(): void
    {
        // Worker::daemonShouldRun() pauses the loop when a Looping listener returns false.
        $this->assertNull(Event::until(new Looping('redis', 'default')));
    }

    public function test_writes_at_most_once_per_second(): void
    {
        // Start right after a second boundary, so both loops below fall into the same second.
        usleep(1_000_000 - (int) (microtime(true) * 1_000_000) % 1_000_000 + 1_000);

        Event::until(new Looping('redis', 'default'));
        $stale = time() - 100;
        touch($this->path, $stale);

        Event::until(new Looping('redis', 'default'));
        clearstatcache();

        $this->assertSame($stale, filemtime($this->path));
    }
}
