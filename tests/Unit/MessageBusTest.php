<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\Bus\MessageBus;
use Jurager\Microservice\Support\HmacSigner;
use Jurager\Microservice\Tests\TestCase;

class MessageBusTest extends TestCase
{
    public function test_verify_accepts_envelope_signed_by_canonicalize(): void
    {
        $signer = app(HmacSigner::class);
        $bus    = new MessageBus($signer);

        // Build a signed envelope manually using the same canonical form as publish().
        $envelope = [
            'type'        => 'sfm.site.updated',
            'service'     => 'sfm',
            'occurred_at' => '2026-05-21T10:00:00+00:00',
            'request_id'  => null,
            'payload'     => ['site_id' => 1],
        ];
        $envelope['signature'] = $signer->signRaw(json_encode(
            [
                'type'        => $envelope['type'],
                'service'     => $envelope['service'],
                'occurred_at' => $envelope['occurred_at'],
                'request_id'  => $envelope['request_id'],
                'payload'     => $envelope['payload'],
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        $this->assertTrue($bus->verify($envelope));
    }

    public function test_verify_rejects_envelope_with_tampered_payload(): void
    {
        $bus = app(MessageBus::class);

        $envelope = [
            'type'        => 'sfm.site.updated',
            'service'     => 'sfm',
            'occurred_at' => '2026-05-21T10:00:00+00:00',
            'request_id'  => null,
            'payload'     => ['site_id' => 1],
        ];
        $signer = app(HmacSigner::class);
        $envelope['signature'] = $signer->signRaw(json_encode(
            ['type' => $envelope['type'], 'service' => $envelope['service'], 'occurred_at' => $envelope['occurred_at'], 'request_id' => null, 'payload' => $envelope['payload']],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        $envelope['payload']['site_id'] = 999;

        $this->assertFalse($bus->verify($envelope));
    }

    public function test_verify_rejects_envelope_without_signature(): void
    {
        $bus = app(MessageBus::class);

        $this->assertFalse($bus->verify([
            'type'    => 'sfm.site.updated',
            'payload' => ['site_id' => 1],
        ]));
    }

    public function test_verify_rejects_envelope_with_empty_signature(): void
    {
        $bus = app(MessageBus::class);

        $this->assertFalse($bus->verify([
            'type'      => 'sfm.site.updated',
            'payload'   => [],
            'signature' => '',
        ]));
    }

    public function test_verify_passes_through_in_debug_mode(): void
    {
        config()->set('microservice.debug', true);

        $bus = app(MessageBus::class);

        $this->assertTrue($bus->verify(['type' => 'whatever', 'payload' => []]));
    }
}
