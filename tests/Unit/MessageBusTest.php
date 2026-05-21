<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Jurager\Microservice\Bus\MessageBus;
use Jurager\Microservice\Support\HmacSigner;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class MessageBusTest extends TestCase
{
    public function test_publish_dispatches_event_with_signed_envelope(): void
    {
        config()->set('microservice.name', 'sfm');

        Event::shouldReceive('dispatch')
            ->once()
            ->with('sfm.site.updated', Mockery::on(function (array $args): bool {
                $envelope = $args[0] ?? null;

                return is_array($envelope)
                    && $envelope['type'] === 'sfm.site.updated'
                    && $envelope['service'] === 'sfm'
                    && $envelope['payload'] === ['site_id' => 1, 'domain' => 'example.com']
                    && array_key_exists('occurred_at', $envelope)
                    && array_key_exists('request_id', $envelope)
                    && is_string($envelope['signature'])
                    && $envelope['signature'] !== '';
            }));

        app(MessageBus::class)->publish(
            'sfm.site.updated',
            ['site_id' => 1, 'domain' => 'example.com'],
        );
    }

    public function test_publish_logs_error_on_exception(): void
    {
        Event::shouldReceive('dispatch')->andThrow(new \RuntimeException('AMQP down'));

        Log::shouldReceive('error')
            ->once()
            ->with('MessageBus: failed to publish', Mockery::on(fn (array $ctx): bool =>
                $ctx['type'] === 'sfm.site.deleted' && $ctx['error'] === 'AMQP down'
            ));

        app(MessageBus::class)->publish('sfm.site.deleted', []);
    }

    public function test_verify_accepts_envelope_signed_by_publish(): void
    {
        $signer = app(HmacSigner::class);
        $bus    = new MessageBus($signer);

        $captured = null;
        Event::listen('sfm.site.updated', function (array $envelope) use (&$captured): void {
            $captured = $envelope;
        });

        $bus->publish('sfm.site.updated', ['site_id' => 42]);

        $this->assertIsArray($captured);
        $this->assertTrue($bus->verify($captured));
    }

    public function test_verify_rejects_envelope_with_tampered_payload(): void
    {
        $bus = app(MessageBus::class);

        $captured = null;
        Event::listen('sfm.site.updated', function (array $envelope) use (&$captured): void {
            $captured = $envelope;
        });

        $bus->publish('sfm.site.updated', ['site_id' => 1]);

        $this->assertIsArray($captured);

        $captured['payload']['site_id'] = 999;

        $this->assertFalse($bus->verify($captured));
    }

    public function test_verify_rejects_envelope_without_signature(): void
    {
        $bus = app(MessageBus::class);

        $this->assertFalse($bus->verify([
            'type'    => 'sfm.site.updated',
            'payload' => ['site_id' => 1],
        ]));
    }

    public function test_verify_passes_through_in_debug_mode(): void
    {
        config()->set('microservice.debug', true);

        $bus = app(MessageBus::class);

        $this->assertTrue($bus->verify(['type' => 'whatever', 'payload' => []]));
    }
}
