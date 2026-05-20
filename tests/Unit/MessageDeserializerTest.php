<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Queue\MessageDeserializer;
use Jurager\Microservice\Tests\TestCase;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class OrderCreatedHandler implements MessageHandler, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $orderId,
        public readonly string $status = 'pending',
    ) {}

    public static function type(): string
    {
        return 'oms.order.created';
    }

    public static function fromMessage(array $payload): static
    {
        return new static($payload['order_id'], $payload['status'] ?? 'pending');
    }

    public function handle(): void {}
}

class SiteUpdatedHandler implements MessageHandler, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $siteId) {}

    public static function type(): string
    {
        return 'sfm.site.updated';
    }

    public static function fromMessage(array $payload): static
    {
        return new static($payload['site_id'] ?? 0);
    }

    public function handle(): void {}
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

class MessageDeserializerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('messages', [
            OrderCreatedHandler::class,
            SiteUpdatedHandler::class,
        ]);
    }

    public function test_deserialize_returns_correct_payload_structure(): void
    {
        $result = (new MessageDeserializer())->deserialize(
            json_encode(['type' => 'oms.order.created', 'payload' => ['order_id' => 42, 'status' => 'paid']]),
        );

        $this->assertSame(OrderCreatedHandler::class, $result['displayName']);
        $this->assertSame('Illuminate\Queue\CallQueuedHandler@call', $result['job']);
        $this->assertSame(OrderCreatedHandler::class, $result['data']['commandName']);
        $this->assertArrayHasKey('command', $result['data']);
    }

    public function test_deserialize_instantiates_handler_via_from_message(): void
    {
        $result = (new MessageDeserializer())->deserialize(
            json_encode(['type' => 'oms.order.created', 'payload' => ['order_id' => 7, 'status' => 'paid']]),
        );

        $job = unserialize($result['data']['command']);

        $this->assertInstanceOf(OrderCreatedHandler::class, $job);
        $this->assertSame(7, $job->orderId);
        $this->assertSame('paid', $job->status);
    }

    public function test_deserialize_routes_to_correct_handler_by_type(): void
    {
        $deserializer = new MessageDeserializer();

        $order = $deserializer->deserialize(
            json_encode(['type' => 'oms.order.created', 'payload' => ['order_id' => 1]]),
        );

        $site = $deserializer->deserialize(
            json_encode(['type' => 'sfm.site.updated', 'payload' => ['site_id' => 99]]),
        );

        $this->assertSame(OrderCreatedHandler::class, $order['displayName']);
        $this->assertSame(SiteUpdatedHandler::class, $site['displayName']);
    }

    public function test_deserialize_defaults_payload_to_empty_array_when_absent(): void
    {
        $result = (new MessageDeserializer())->deserialize(
            json_encode(['type' => 'sfm.site.updated']),
        );

        $job = unserialize($result['data']['command']);

        $this->assertInstanceOf(SiteUpdatedHandler::class, $job);
        $this->assertSame(0, $job->siteId);
    }

    public function test_deserialize_throws_when_type_is_missing(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Missing message type');

        (new MessageDeserializer())->deserialize(
            json_encode(['payload' => ['order_id' => 1]]),
        );
    }

    public function test_deserialize_throws_when_type_has_no_handler(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('No handler registered for message type [unknown.event]');

        (new MessageDeserializer())->deserialize(
            json_encode(['type' => 'unknown.event', 'payload' => []]),
        );
    }

    public function test_deserialize_throws_on_invalid_json(): void
    {
        $this->expectException(\JsonException::class);

        (new MessageDeserializer())->deserialize('not valid json {{{');
    }

    public function test_routing_table_is_built_from_config_messages(): void
    {
        $this->app['config']->set('messages', [OrderCreatedHandler::class]);

        $deserializer = new MessageDeserializer();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('No handler registered for message type [sfm.site.updated]');

        $deserializer->deserialize(json_encode(['type' => 'sfm.site.updated', 'payload' => []]));
    }
}
