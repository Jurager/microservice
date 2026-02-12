<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Http\Middleware\TrustService;
use Jurager\Microservice\Support\HmacSigner;
use Jurager\Microservice\Tests\TestCase;

class TrustServiceMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('/test/service-endpoint', fn () => response()->json(['ok' => true]))
            ->middleware(TrustService::class);
    }

    public function test_passes_with_valid_signature_and_service_name(): void
    {
        $signer = $this->app->make(HmacSigner::class);
        $timestamp = (string) time();
        $body = json_encode([]);

        $this->postJson('/test/service-endpoint', [], [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signer->sign('POST', '/test/service-endpoint', $timestamp, $body),
            'X-Service-Name' => 'test-service',
        ])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_rejects_missing_service_name_header(): void
    {
        $signer = $this->app->make(HmacSigner::class);
        $timestamp = (string) time();
        $body = json_encode([]);

        $this->postJson('/test/service-endpoint', [], [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signer->sign('POST', '/test/service-endpoint', $timestamp, $body),
        ])
            ->assertStatus(401)
            ->assertJson(['message' => 'Missing service name header.']);
    }

    public function test_rejects_invalid_signature_with_service_name(): void
    {
        $this->postJson('/test/service-endpoint', [], [
            'X-Timestamp' => (string) time(),
            'X-Signature' => 'invalid',
            'X-Service-Name' => 'test-service',
        ])
            ->assertStatus(401);
    }
}
