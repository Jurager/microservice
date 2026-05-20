<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Jurager\Microservice\Bus\MessageBus;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class MessageBusTest extends TestCase
{
    public function test_publish_sends_json_envelope_to_queue(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('pushRaw')
            ->once()
            ->with(
                json_encode(['type' => 'sfm.site.updated', 'payload' => ['site_id' => 1, 'domain' => 'example.com']]),
                'api.sfm.cache',
            );

        Queue::shouldReceive('connection')->with('rabbitmq')->once()->andReturn($connection);

        (new MessageBus('rabbitmq'))->publish(
            'sfm.site.updated',
            ['site_id' => 1, 'domain' => 'example.com'],
            'api.sfm.cache',
        );
    }

    public function test_publish_uses_configured_connection(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('pushRaw')->once();

        Queue::shouldReceive('connection')->with('custom-connection')->once()->andReturn($connection);

        (new MessageBus('custom-connection'))->publish('foo.bar', [], 'some-queue');
    }

    public function test_publish_does_not_throw_on_queue_exception(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('pushRaw')->andThrow(new \RuntimeException('Connection refused'));

        Queue::shouldReceive('connection')->andReturn($connection);
        Log::spy();

        (new MessageBus('rabbitmq'))->publish('sfm.site.updated', ['site_id' => 1], 'api.sfm.cache');

        $this->addToAssertionCount(1);
    }

    public function test_publish_logs_error_with_context_on_exception(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('pushRaw')->andThrow(new \RuntimeException('Connection refused'));

        Queue::shouldReceive('connection')->andReturn($connection);

        Log::shouldReceive('error')
            ->once()
            ->with('MessageBus: failed to publish', Mockery::on(fn (array $ctx) =>
                $ctx['type'] === 'sfm.site.updated' &&
                $ctx['queue'] === 'api.sfm.cache' &&
                $ctx['error'] === 'Connection refused'
            ));

        (new MessageBus('rabbitmq'))->publish('sfm.site.updated', ['site_id' => 1], 'api.sfm.cache');
    }

    public function test_publish_encodes_empty_payload(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('pushRaw')
            ->once()
            ->with(json_encode(['type' => 'sfm.site.deleted', 'payload' => []]), 'api.sfm.cache');

        Queue::shouldReceive('connection')->andReturn($connection);

        (new MessageBus('rabbitmq'))->publish('sfm.site.deleted', [], 'api.sfm.cache');
    }
}
