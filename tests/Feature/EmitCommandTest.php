<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Tests\TestCase;
use Mockery;
use RabbitEvents\Publisher\Publisher;
use RabbitEvents\Publisher\ShouldPublish;

class EmitCommandTest extends TestCase
{
    public function test_emits_event_via_message_bus(): void
    {
        $publisher = Mockery::mock(Publisher::class);
        $publisher->shouldReceive('publish')
            ->once()
            ->with(Mockery::on(function (ShouldPublish $event): bool {
                if ($event->publishEventKey() !== 'test.foo') {
                    return false;
                }

                $payload = $event->toPublish();
                // nuwber wraps the assoc envelope into [$envelope]
                $envelope = is_array($payload) && array_is_list($payload) ? $payload[0] : $payload;

                return is_array($envelope)
                    && ($envelope['type'] ?? null) === 'test.foo'
                    && ($envelope['payload'] ?? null) === ['k' => 'v']
                    && is_string($envelope['signature'] ?? null);
            }));

        $this->app->instance(Publisher::class, $publisher);

        $this->artisan('microservice:emit', ['type' => 'test.foo', 'payload' => '{"k":"v"}'])
            ->expectsOutputToContain('Published event [test.foo]')
            ->assertSuccessful();
    }

    public function test_fails_on_invalid_json_payload(): void
    {
        $publisher = Mockery::mock(Publisher::class);
        $publisher->shouldNotReceive('publish');
        $this->app->instance(Publisher::class, $publisher);

        $this->artisan('microservice:emit', ['type' => 'test.foo', 'payload' => 'not json'])
            ->expectsOutputToContain('Invalid JSON payload')
            ->assertFailed();
    }

    public function test_fails_when_payload_is_not_object_or_array(): void
    {
        $publisher = Mockery::mock(Publisher::class);
        $publisher->shouldNotReceive('publish');
        $this->app->instance(Publisher::class, $publisher);

        $this->artisan('microservice:emit', ['type' => 'test.foo', 'payload' => '"just-a-string"'])
            ->expectsOutputToContain('Payload must be a JSON object/array')
            ->assertFailed();
    }
}
