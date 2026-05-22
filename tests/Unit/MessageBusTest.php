<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Illuminate\Support\Facades\Log;
use Jurager\Microservice\Bus\Connection;
use Jurager\Microservice\Bus\MessageBus;
use Jurager\Microservice\Tests\TestCase;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

class MessageBusTest extends TestCase
{
    public function test_publish_sends_signed_envelope_to_topic_exchange(): void
    {
        config()->set('microservice.name', 'sfm');

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_publish')
            ->once()
            ->withArgs(function (AMQPMessage $msg, string $exchange, string $routingKey): bool {
                $envelope = json_decode($msg->getBody(), true);

                return $exchange === 'events'
                    && $routingKey === 'sfm.site.updated'
                    && $msg->get('content_type') === 'application/json'
                    && is_array($envelope)
                    && $envelope['type'] === 'sfm.site.updated'
                    && $envelope['service'] === 'sfm'
                    && $envelope['payload'] === ['site_id' => 1]
                    && is_string($envelope['signature']);
            });

        $this->bindChannel($channel);

        app(MessageBus::class)->publish('sfm.site.updated', ['site_id' => 1]);
    }

    public function test_publish_is_noop_when_bus_disabled(): void
    {
        config()->set('microservice.bus.enabled', false);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldNotReceive('channel');
        $this->app->instance(Connection::class, $connection);

        Log::shouldReceive('debug')->once();

        app(MessageBus::class)->publish('test', ['x' => 1]);

        $this->addToAssertionCount(1);
    }

    public function test_publish_logs_error_on_exception(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_publish')->andThrow(new \RuntimeException('AMQP down'));
        $this->bindChannel($channel);

        Log::shouldReceive('error')
            ->once()
            ->with('MessageBus: failed to publish', Mockery::on(
                fn (array $ctx): bool =>
                $ctx['type'] === 'sfm.site.deleted' && $ctx['error'] === 'AMQP down'
            ));

        app(MessageBus::class)->publish('sfm.site.deleted', []);
    }

    public function test_verify_accepts_envelope_signed_by_publish(): void
    {
        $captured = null;
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_publish')
            ->andReturnUsing(function (AMQPMessage $msg) use (&$captured): void {
                $captured = json_decode($msg->getBody(), true);
            });

        $this->bindChannel($channel);

        $bus = app(MessageBus::class);
        $bus->publish('sfm.site.updated', ['site_id' => 42]);

        $this->assertIsArray($captured);
        $this->assertTrue($bus->verify($captured));
    }

    public function test_verify_rejects_envelope_with_tampered_payload(): void
    {
        $captured = null;
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_publish')
            ->andReturnUsing(function (AMQPMessage $msg) use (&$captured): void {
                $captured = json_decode($msg->getBody(), true);
            });

        $this->bindChannel($channel);

        $bus = app(MessageBus::class);
        $bus->publish('sfm.site.updated', ['site_id' => 1]);

        $captured['payload']['site_id'] = 999;

        $this->assertFalse($bus->verify($captured));
    }

    public function test_verify_rejects_envelope_without_signature(): void
    {
        $this->assertFalse(app(MessageBus::class)->verify([
            'type'    => 'sfm.site.updated',
            'payload' => ['site_id' => 1],
        ]));
    }

    public function test_verify_passes_through_in_debug_mode(): void
    {
        config()->set('microservice.debug', true);

        $this->assertTrue(app(MessageBus::class)->verify(['type' => 'whatever', 'payload' => []]));
    }

    private function bindChannel(AMQPChannel $channel): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('channel')->andReturn($channel);
        $connection->shouldReceive('exchange')->andReturn('events');

        $this->app->instance(Connection::class, $connection);
    }
}
