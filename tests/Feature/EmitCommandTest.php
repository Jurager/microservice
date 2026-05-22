<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Bunny\Channel;
use Jurager\Microservice\Bus\Connection;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class EmitCommandTest extends TestCase
{
    public function test_emits_event_via_message_bus(): void
    {
        $channel = Mockery::mock(Channel::class);
        $channel->shouldReceive('publish')
            ->once()
            ->withArgs(function (string $body, array $headers, string $exchange, string $routingKey): bool {
                $envelope = json_decode($body, true);

                return $exchange === 'events'
                    && $routingKey === 'test.foo'
                    && is_array($envelope)
                    && $envelope['type'] === 'test.foo'
                    && $envelope['payload'] === ['k' => 'v']
                    && is_string($envelope['signature']);
            });

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('channel')->andReturn($channel);
        $connection->shouldReceive('exchange')->andReturn('events');
        $this->app->instance(Connection::class, $connection);

        $this->artisan('microservice:emit', ['type' => 'test.foo', 'payload' => '{"k":"v"}'])
            ->expectsOutputToContain('Published event [test.foo]')
            ->assertSuccessful();
    }

    public function test_fails_on_invalid_json_payload(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldNotReceive('channel');
        $this->app->instance(Connection::class, $connection);

        $this->artisan('microservice:emit', ['type' => 'test.foo', 'payload' => 'not json'])
            ->expectsOutputToContain('Invalid JSON payload')
            ->assertFailed();
    }

    public function test_fails_when_payload_is_not_object_or_array(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldNotReceive('channel');
        $this->app->instance(Connection::class, $connection);

        $this->artisan('microservice:emit', ['type' => 'test.foo', 'payload' => '"just-a-string"'])
            ->expectsOutputToContain('Payload must be a JSON object/array')
            ->assertFailed();
    }
}
