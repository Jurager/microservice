<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Tests\TestCase;

class EventsCommandTest extends TestCase
{
    public function test_outputs_table_of_discovered_handlers(): void
    {
        $this->fakeDiscovery([SampleSyncHandler::class, SampleQueuedHandler::class]);

        $this->artisan('microservice:events')->assertSuccessful();

        Artisan::call('microservice:events');
        $output = Artisan::output();

        $this->assertStringContainsString('test.sample.sync', $output);
        $this->assertStringContainsString('test.sample.queued', $output);
        $this->assertStringContainsString('SampleSyncHandler', $output);
        $this->assertStringContainsString('SampleQueuedHandler', $output);
        $this->assertStringContainsString('sync', $output);
        $this->assertStringContainsString('queued', $output);
    }

    public function test_warns_when_no_handlers_discovered(): void
    {
        $this->fakeDiscovery([]);

        $this->artisan('microservice:events')
            ->expectsOutputToContain('No MessageHandler implementations found')
            ->assertSuccessful();
    }

    private function fakeDiscovery(array $classes): void
    {
        $this->app->instance(HandlerDiscovery::class, new class ($classes) extends HandlerDiscovery {
            public function __construct(private array $classes)
            {
            }

            public function discover(): array
            {
                return $this->classes;
            }
        });
    }
}

class SampleSyncHandler implements MessageHandler
{
    public function __construct(public readonly array $payload = [])
    {
    }

    public static function type(): string
    {
        return 'test.sample.sync';
    }

    public static function from(array $payload): static
    {
        return new static($payload);
    }

    public function handle(): void
    {
    }
}

class SampleQueuedHandler implements MessageHandler, ShouldQueue
{
    public function __construct(public readonly array $payload = [])
    {
    }

    public static function type(): string
    {
        return 'test.sample.queued';
    }

    public static function from(array $payload): static
    {
        return new static($payload);
    }

    public function handle(): void
    {
    }
}
