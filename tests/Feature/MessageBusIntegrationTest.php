<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\MessageBus;
use Jurager\Microservice\Tests\TestCase;
use RabbitEvents\Listener\Dispatcher as RabbitEventsDispatcher;

/**
 * End-to-end tests against the real RabbitEvents Dispatcher (no AMQP transport).
 *
 * Verifies that our auto-registration:
 *   - actually attaches listeners on nuwber's Dispatcher (not on Laravel's),
 *     so `rabbitevents:listen` picks them up;
 *   - wraps each MessageHandler in a closure that verifies the envelope
 *     signature before invocation;
 *   - routes ShouldQueue handlers to the Laravel queue instead of running
 *     them inline in the listener process.
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

    /**
     * Force console mode so MicroserviceServiceProvider::registerMessageHandlers()
     * runs (it gates on runningInConsole() to mirror nuwber's own boot behavior).
     */
    protected function resolveApplicationConsoleKernel($app)
    {
        return parent::resolveApplicationConsoleKernel($app);
    }

    public function test_listeners_are_registered_on_rabbitevents_dispatcher(): void
    {
        $dispatcher = $this->app->make(RabbitEventsDispatcher::class);
        $events = $dispatcher->getEvents();

        $this->assertContains('test.sync', $events);
        $this->assertContains('test.queued', $events);
    }

    public function test_sync_handler_is_invoked_when_dispatcher_fires_signed_envelope(): void
    {
        FakeSyncHandler::$invocations = [];

        $envelope = $this->signedEnvelope('test.sync', ['x' => 1]);

        $this->app->make(RabbitEventsDispatcher::class)->dispatch('test.sync', [$envelope]);

        $this->assertCount(1, FakeSyncHandler::$invocations);
        $this->assertSame(['x' => 1], FakeSyncHandler::$invocations[0]);
    }

    public function test_should_queue_handler_is_dispatched_to_queue_not_executed_inline(): void
    {
        Bus::fake([FakeQueuedHandler::class]);
        FakeQueuedHandler::$invocations = [];

        $envelope = $this->signedEnvelope('test.queued', ['site_id' => 7]);

        $this->app->make(RabbitEventsDispatcher::class)->dispatch('test.queued', [$envelope]);

        Bus::assertDispatched(FakeQueuedHandler::class, fn (FakeQueuedHandler $job) => $job->siteId === 7);
        $this->assertCount(0, FakeQueuedHandler::$invocations, 'ShouldQueue handler must not run inline in the listener');
    }

    public function test_listener_rejects_envelope_with_invalid_signature(): void
    {
        FakeSyncHandler::$invocations = [];

        $envelope = [
            'type'        => 'test.sync',
            'service'     => 'attacker',
            'occurred_at' => now()->toIso8601String(),
            'request_id'  => null,
            'payload'     => ['x' => 'tampered'],
            'signature'   => 'fake-signature',
        ];

        $this->app->make(RabbitEventsDispatcher::class)->dispatch('test.sync', [$envelope]);

        $this->assertCount(0, FakeSyncHandler::$invocations);
    }

    public function test_listener_accepts_unsigned_envelope_in_debug_mode(): void
    {
        config()->set('microservice.debug', true);
        FakeSyncHandler::$invocations = [];

        $envelope = [
            'type'    => 'test.sync',
            'payload' => ['x' => 42],
        ];

        $this->app->make(RabbitEventsDispatcher::class)->dispatch('test.sync', [$envelope]);

        $this->assertCount(1, FakeSyncHandler::$invocations);
        $this->assertSame(['x' => 42], FakeSyncHandler::$invocations[0]);
    }

    private function signedEnvelope(string $type, array $payload): array
    {
        $envelope = [
            'type'        => $type,
            'service'     => 'test-service',
            'occurred_at' => now()->toIso8601String(),
            'request_id'  => null,
            'payload'     => $payload,
        ];

        $envelope['signature'] = app(\Jurager\Microservice\Support\HmacSigner::class)->signRaw(
            json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $envelope;
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
