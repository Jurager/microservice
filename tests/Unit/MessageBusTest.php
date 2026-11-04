<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jurager\Microservice\Bus\Connection;
use Jurager\Microservice\Bus\MessageBus;
use Jurager\Microservice\Tests\TestCase;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

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
                    && is_string($envelope['signature'])
                    && is_string($envelope['certificate']);
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

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('debug')->once();
        $this->app->instance(LoggerInterface::class, $logger);

        app(MessageBus::class)->publish('test', ['x' => 1]);

        $this->addToAssertionCount(1);
    }

    public function test_publish_logs_error_on_exception(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_publish')->andThrow(new \RuntimeException('AMQP down'));

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('channel')->andReturn($channel);
        $connection->shouldReceive('exchange')->andReturn('events');
        $connection->shouldReceive('close')->once();
        $this->app->instance(Connection::class, $connection);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with('MessageBus: failed to publish', Mockery::on(
                fn (array $ctx): bool => $ctx['type'] === 'sfm.site.deleted' && $ctx['error'] === 'AMQP down'
            ));
        $this->app->instance(LoggerInterface::class, $logger);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AMQP down');

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
            'type' => 'sfm.site.updated',
            'payload' => ['site_id' => 1],
        ]));
    }

    public function test_verify_rejects_envelope_with_certificate_for_a_different_service(): void
    {
        $signer = $this->app->make(\Jurager\Microservice\Signing\Signer::class);

        $envelope = [
            'type' => 'sfm.site.updated',
            'service' => 'never-trusted',
            'occurred_at' => now()->toIso8601String(),
            'request_id' => null,
            'payload' => ['site_id' => 1],
        ];

        // Signed by, and certified for, 'test-service' — but claiming to be
        // 'never-trusted' here.
        $envelope['signature'] = $signer->signRaw(json_encode($envelope, JSON_UNESCAPED_SLASHES));
        $envelope['certificate'] = $signer->certificate();

        $this->assertFalse(app(MessageBus::class)->verify($envelope));
    }

    public function test_verify_passes_through_with_signing_switched_off(): void
    {
        config()->set('microservice.signing.enabled', false);

        $this->assertTrue(app(MessageBus::class)->verify(['type' => 'whatever', 'payload' => []]));
    }

    /** Publish one event and return the envelope that reached the broker. */
    private function published(string $type = 'sfm.site.updated', array $payload = ['site_id' => 1], ?callable $around = null): ?array
    {
        $captured = null;
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_publish')->andReturnUsing(function (AMQPMessage $msg) use (&$captured): void {
            $captured = [...json_decode($msg->getBody(), true), '_message_id' => $msg->get('message_id')];
        });
        $channel->shouldReceive('wait_for_pending_acks_returns');

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('channel')->andReturn($channel);
        $connection->shouldReceive('exchange')->andReturn('events');
        $this->app->instance(Connection::class, $connection);
        $this->app->forgetInstance(MessageBus::class);

        $publish = fn () => app(MessageBus::class)->publish($type, $payload);
        $around !== null ? $around($publish) : $publish();

        return $captured;
    }

    public function test_every_envelope_carries_a_signed_id_of_its_own(): void
    {
        $envelope = $this->published();

        $this->assertTrue(Str::isUuid($envelope['id']));
        $this->assertSame($envelope['id'], $envelope['_message_id']);

        $bus = app(MessageBus::class);
        unset($envelope['_message_id']);
        $this->assertTrue($bus->verify($envelope));

        $this->assertFalse($bus->verify([...$envelope, 'id' => (string) Str::uuid()]), 'Another id breaks the signature.');

        unset($envelope['id']);
        $this->assertFalse($bus->verify($envelope), 'Removing the id breaks the signature.');
    }

    public function test_an_envelope_signed_without_its_id_is_refused(): void
    {
        $signer = $this->app->make(\Jurager\Microservice\Signing\Signer::class);
        $envelope = ['type' => 'sfm.site.updated', 'service' => 'test-service', 'occurred_at' => now()->toIso8601String(), 'request_id' => null, 'payload' => ['site_id' => 1]];
        $envelope['signature'] = $signer->signRaw(json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $envelope['certificate'] = $signer->certificate();

        $this->assertFalse(app(MessageBus::class)->verify($envelope), 'What 1.x publishes does not verify.');
    }

    public function test_the_request_id_comes_from_the_context(): void
    {
        Context::add('request_id', 'a1a2a3a4-b1b2-c1c2-d1d2-d3d4d5d6d7d8');

        $this->assertSame('a1a2a3a4-b1b2-c1c2-d1d2-d3d4d5d6d7d8', $this->published()['request_id']);
    }

    public function test_inside_a_transaction_the_event_is_published_once_it_commits(): void
    {
        $this->assertNull($this->published(around: function (callable $publish): void {
            try {
                DB::transaction(function () use ($publish): void {
                    $publish();

                    throw new \RuntimeException('rolled back');
                });
            } catch (\RuntimeException) {
            }
        }), 'Work that was rolled back publishes nothing.');

        $this->assertNotNull($this->published(around: fn (callable $publish) => DB::transaction($publish)));
    }

    private function bindChannel(AMQPChannel $channel): void
    {
        // A publish is only complete once the broker has accounted for it.
        $channel->shouldReceive('wait_for_pending_acks_returns')->once();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('channel')->andReturn($channel);
        $connection->shouldReceive('exchange')->andReturn('events');

        $this->app->instance(Connection::class, $connection);
    }
}
