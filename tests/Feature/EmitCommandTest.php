<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class EmitCommandTest extends TestCase
{
    public function test_emits_event_via_message_bus(): void
    {
        Event::shouldReceive('dispatch')
            ->once()
            ->with('test.foo', Mockery::on(fn (array $args): bool =>
                ($args[0]['type'] ?? null) === 'test.foo'
                && ($args[0]['payload'] ?? null) === ['k' => 'v']
                && is_string($args[0]['signature'] ?? null)
            ));

        $this->artisan('microservice:emit', ['type' => 'test.foo', 'payload' => '{"k":"v"}'])
            ->expectsOutputToContain('Published event [test.foo]')
            ->assertSuccessful();
    }

    public function test_fails_on_invalid_json_payload(): void
    {
        Event::shouldReceive('dispatch')->never();

        $this->artisan('microservice:emit', ['type' => 'test.foo', 'payload' => 'not json'])
            ->expectsOutputToContain('Invalid JSON payload')
            ->assertFailed();
    }

    public function test_fails_when_payload_is_not_object_or_array(): void
    {
        Event::shouldReceive('dispatch')->never();

        $this->artisan('microservice:emit', ['type' => 'test.foo', 'payload' => '"just-a-string"'])
            ->expectsOutputToContain('Payload must be a JSON object/array')
            ->assertFailed();
    }
}
