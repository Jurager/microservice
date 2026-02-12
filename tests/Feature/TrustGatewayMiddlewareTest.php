<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Http\Middleware\TrustGateway;
use Jurager\Microservice\Support\HmacSigner;
use Jurager\Microservice\Tests\TestCase;

class TrustGatewayMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('/test/endpoint', fn () => response()->json(['ok' => true]))
            ->middleware(TrustGateway::class);
    }

    private function signedRequest(string $method, string $path, array $data = []): \Illuminate\Testing\TestResponse
    {
        $signer = $this->app->make(HmacSigner::class);
        $timestamp = (string) time();
        $body = json_encode($data);

        $headers = [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signer->sign($method, $path, $timestamp, $body),
        ];

        return $this->postJson($path, $data, $headers);
    }

    public function test_passes_with_valid_signature(): void
    {
        $this->signedRequest('POST', '/test/endpoint')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_rejects_missing_signature_headers(): void
    {
        $this->postJson('/test/endpoint')
            ->assertStatus(401)
            ->assertJson(['message' => 'Missing signature headers.']);
    }

    public function test_rejects_invalid_signature(): void
    {
        $this->postJson('/test/endpoint', [], [
            'X-Signature' => 'invalid',
            'X-Timestamp' => (string) time(),
        ])
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid signature or timestamp.']);
    }

    public function test_rejects_expired_timestamp(): void
    {
        $signer = $this->app->make(HmacSigner::class);
        $timestamp = (string) (time() - 120);
        $body = json_encode([]);

        $this->postJson('/test/endpoint', [], [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signer->sign('POST', '/test/endpoint', $timestamp, $body),
        ])
            ->assertStatus(401);
    }
}
