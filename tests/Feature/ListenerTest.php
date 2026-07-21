<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\Listener;
use Jurager\Microservice\Support\HmacSigner;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

/**
 * Tests Listener directly — the AMQP transport is exercised separately
 * via ListenCommand, but message routing logic lives here.
 */
class ListenerTest extends TestCase
{
    public function test_sync_handler_is_invoked_inline_for_valid_envelope(): void
    {
        FakeSyncHandler::$invocations = [];

        $envelope = $this->signedEnvelope('test.sync', ['x' => 1]);
        $result = app(Listener::class)->handle(FakeSyncHandler::class, $envelope);

        $this->assertTrue($result);
        $this->assertCount(1, FakeSyncHandler::$invocations);
        $this->assertSame(['x' => 1], FakeSyncHandler::$invocations[0]);
    }

    public function test_should_queue_handler_is_dispatched_to_queue_not_executed_inline(): void
    {
        Bus::fake([FakeQueuedHandler::class]);
        FakeQueuedHandler::$invocations = [];

        $envelope = $this->signedEnvelope('test.queued', ['site_id' => 7]);
        $result = app(Listener::class)->handle(FakeQueuedHandler::class, $envelope);

        $this->assertTrue($result);
        Bus::assertDispatched(FakeQueuedHandler::class, fn (FakeQueuedHandler $job) => $job->siteId === 7);
        $this->assertCount(0, FakeQueuedHandler::$invocations, 'ShouldQueue handler must not run inline');
    }

    public function test_rejects_envelope_with_invalid_signature(): void
    {
        FakeSyncHandler::$invocations = [];

        Log::shouldReceive('warning')
            ->once()
            ->with('Listener: rejected envelope with invalid or missing signature', Mockery::any());

        $envelope = [
            'type' => 'test.sync',
            'service' => 'attacker',
            'occurred_at' => now()->toIso8601String(),
            'request_id' => null,
            'payload' => ['x' => 'tampered'],
            'signature' => 'fake-signature',
        ];

        $result = app(Listener::class)->handle(FakeSyncHandler::class, $envelope);

        $this->assertFalse($result);
        $this->assertCount(0, FakeSyncHandler::$invocations);
    }

    public function test_accepts_unsigned_envelope_in_debug_mode(): void
    {
        config()->set('microservice.debug', true);
        FakeSyncHandler::$invocations = [];

        $envelope = ['type' => 'test.sync', 'payload' => ['x' => 42]];
        $result = app(Listener::class)->handle(FakeSyncHandler::class, $envelope);

        $this->assertTrue($result);
        $this->assertSame(['x' => 42], FakeSyncHandler::$invocations[0]);
    }

    public function test_rejects_non_messagehandler_class(): void
    {
        Log::shouldReceive('error')->once()->with('Listener: not a MessageHandler', Mockery::any());

        $result = app(Listener::class)->handle(\stdClass::class, ['payload' => []]);

        $this->assertFalse($result);
    }

    public function test_logs_and_returns_false_when_handler_throws(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('Listener: handler threw', Mockery::on(
                fn (array $ctx): bool => $ctx['class'] === ThrowingHandler::class && $ctx['error'] === 'boom'
            ));

        $envelope = $this->signedEnvelope('test.throws', []);
        $result = app(Listener::class)->handle(ThrowingHandler::class, $envelope);

        $this->assertFalse($result);
    }

    private function signedEnvelope(string $type, array $payload): array
    {
        $envelope = [
            'type' => $type,
            'service' => 'test-service',
            'occurred_at' => now()->toIso8601String(),
            'request_id' => null,
            'payload' => $payload,
        ];

        $envelope['signature'] = app(HmacSigner::class)->signRaw(
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

    public static function from(array $payload, string $type = ''): static
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

    public static function from(array $payload, string $type = ''): static
    {
        return new static((int) ($payload['site_id'] ?? 0));
    }

    public function handle(): void
    {
        self::$invocations[] = $this->siteId;
    }
}

class ThrowingHandler implements MessageHandler
{
    public function __construct(public readonly array $payload)
    {
    }

    public static function type(): string
    {
        return 'test.throws';
    }

    public static function from(array $payload, string $type = ''): static
    {
        return new static($payload);
    }

    public function handle(): void
    {
        throw new \RuntimeException('boom');
    }
}
