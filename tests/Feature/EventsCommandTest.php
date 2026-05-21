<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Tests\TestCase;

class EventsCommandTest extends TestCase
{
    public function test_outputs_table_of_registered_handlers(): void
    {
        config()->set('messages', [SampleSyncHandler::class, SampleQueuedHandler::class]);

        $this->artisan('microservice:events')->assertSuccessful();

        \Illuminate\Support\Facades\Artisan::call('microservice:events');
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('test.sample.sync', $output);
        $this->assertStringContainsString('test.sample.queued', $output);
        $this->assertStringContainsString('SampleSyncHandler', $output);
        $this->assertStringContainsString('SampleQueuedHandler', $output);
        $this->assertStringContainsString('sync', $output);
        $this->assertStringContainsString('queued', $output);
    }

    public function test_warns_when_no_handlers_registered(): void
    {
        config()->set('messages', []);

        $this->artisan('microservice:events')
            ->expectsOutputToContain('No message handlers registered')
            ->assertSuccessful();
    }
}

class SampleSyncHandler implements MessageHandler
{
    public function __construct(public readonly array $payload = []) {}
    public static function type(): string { return 'test.sample.sync'; }
    public static function fromMessage(array $payload): static { return new static($payload); }
    public function handle(): void {}
}

class SampleQueuedHandler implements MessageHandler, ShouldQueue
{
    public function __construct(public readonly array $payload = []) {}
    public static function type(): string { return 'test.sample.queued'; }
    public static function fromMessage(array $payload): static { return new static($payload); }
    public function handle(): void {}
}
