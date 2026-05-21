<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\MessageBus;
use Jurager\Microservice\Tests\TestCase;

/**
 * End-to-end tests exercising the publish → dispatch → verify → handle pipeline
 * as it runs in production, including:
 *
 * - Auto-registration of MessageHandler classes from config/messages.php
 * - HMAC envelope signing on publish
 * - HMAC envelope verification on receive
 * - Routing to Laravel queue for ShouldQueue handlers
 * - Synchronous invocation for non-queueable handlers
 *
 * Nuwber's AMQP transport itself isn't booted (that requires a real broker),
 * but everything our package owns on top of it is covered.
 */
class MessageBusIntegrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('messages', [
            FakeSyncHandler::class,
            FakeQueuedHandler::class,
        ]);
    }

    public function test_sync_handler_is_invoked_when_event_is_dispatched(): void
    {
        FakeSyncHandler::$invocations = [];

        app(MessageBus::class)->publish('test.sync', ['x' => 1]);

        $this->assertCount(1, FakeSyncHandler::$invocations);
        $this->assertSame(['x' => 1], FakeSyncHandler::$invocations[0]);
    }

    public function test_should_queue_handler_is_dispatched_to_queue_not_executed_inline(): void
    {
        Bus::fake([FakeQueuedHandler::class]);
        FakeQueuedHandler::$invocations = [];

        app(MessageBus::class)->publish('test.queued', ['site_id' => 7]);

        Bus::assertDispatched(FakeQueuedHandler::class, fn (FakeQueuedHandler $job) => $job->siteId === 7);
        $this->assertCount(0, FakeQueuedHandler::$invocations, 'ShouldQueue handler must not run inline in the listener');
    }

    public function test_listener_rejects_envelope_with_invalid_signature(): void
    {
        FakeSyncHandler::$invocations = [];

        Log::shouldReceive('warning')
            ->once()
            ->with('MessageBus: rejected envelope with invalid or missing signature', \Mockery::any());

        // Dispatch a forged envelope directly (bypassing MessageBus::publish which would sign it)
        Event::dispatch('test.sync', [[
            'type'        => 'test.sync',
            'service'     => 'attacker',
            'occurred_at' => now()->toIso8601String(),
            'request_id'  => null,
            'payload'     => ['x' => 'tampered'],
            'signature'   => 'fake-signature',
        ]]);

        $this->assertCount(0, FakeSyncHandler::$invocations);
    }

    public function test_listener_accepts_unsigned_envelope_in_debug_mode(): void
    {
        config()->set('microservice.debug', true);
        FakeSyncHandler::$invocations = [];

        Event::dispatch('test.sync', [[
            'type'    => 'test.sync',
            'payload' => ['x' => 42],
        ]]);

        $this->assertCount(1, FakeSyncHandler::$invocations);
        $this->assertSame(['x' => 42], FakeSyncHandler::$invocations[0]);
    }
}

class FakeSyncHandler implements MessageHandler
{
    /** @var list<array> */
    public static array $invocations = [];

    public function __construct(public readonly array $payload)
    {
    }

    public static function type(): string
    {
        return 'test.sync';
    }

    public static function fromMessage(array $payload): static
    {
        return new static($payload);
    }

    public function handle(): void
    {
        self::$invocations[] = $this->payload;
    }
}

class FakeQueuedHandler implements MessageHandler, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** @var list<int> */
    public static array $invocations = [];

    public function __construct(public readonly int $siteId)
    {
    }

    public static function type(): string
    {
        return 'test.queued';
    }

    public static function fromMessage(array $payload): static
    {
        return new static((int) ($payload['site_id'] ?? 0));
    }

    public function handle(): void
    {
        self::$invocations[] = $this->siteId;
    }
}
