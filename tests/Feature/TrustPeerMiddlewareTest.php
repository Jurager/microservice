<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Testing\TestResponse;
use Jurager\Microservice\Http\Middleware\TrustPeer;
use Jurager\Microservice\Support\Signer;
use Jurager\Microservice\Tests\TestCase;

class TrustPeerMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('/test/endpoint', fn () => response()->json(['ok' => true]))
            ->middleware(TrustPeer::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 'test-service' trusting itself lets requests signed with its own
        // key verify as coming from 'test-service' — the mechanics under
        // test here, not any particular peer relationship.
        $this->trustPeer('test-service', static::$publicKey);
    }

    private function signedRequest(string $method, string $path, array $data = []): TestResponse
    {
        $signer = $this->app->make(Signer::class);
        $timestamp = (string) time();
        $body = json_encode($data);

        $headers = [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signer->sign($method, $path, $timestamp, $body),
            'X-Service-Name' => 'test-service',
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
            ->assertJson(['errors' => [['detail' => 'Missing signature headers.']]]);
    }

    public function test_rejects_missing_service_name_header(): void
    {
        $signer = $this->app->make(Signer::class);
        $timestamp = (string) time();
        $body = json_encode([]);

        $this->postJson('/test/endpoint', [], [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signer->sign('POST', '/test/endpoint', $timestamp, $body),
        ])
            ->assertStatus(401)
            ->assertJson(['errors' => [['detail' => 'Missing service name header.']]]);
    }

    public function test_rejects_invalid_signature(): void
    {
        $this->postJson('/test/endpoint', [], [
            'X-Signature' => 'invalid',
            'X-Timestamp' => (string) time(),
            'X-Service-Name' => 'test-service',
        ])
            ->assertStatus(401)
            ->assertJson(['errors' => [['detail' => 'Invalid signature or timestamp.']]]);
    }

    public function test_rejects_signature_claiming_a_different_service(): void
    {
        $signer = $this->app->make(Signer::class);
        $timestamp = (string) time();
        $body = json_encode([]);

        $this->postJson('/test/endpoint', [], [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signer->sign('POST', '/test/endpoint', $timestamp, $body),
            'X-Service-Name' => 'unknown-service', // signed as 'test-service', claimed as someone else
        ])
            ->assertStatus(401);
    }

    public function test_rejects_signature_from_an_untrusted_peer(): void
    {
        $peerKeys = self::generateKeyPair();
        // Deliberately not trusted — no trustPeer() call for 'billing'.
        $peerSigner = new Signer(privateKey: $peerKeys['private']);

        $timestamp = (string) time();
        $body = json_encode([]);

        $this->postJson('/test/endpoint', [], [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $peerSigner->sign('POST', '/test/endpoint', $timestamp, $body),
            'X-Service-Name' => 'billing',
        ])
            ->assertStatus(401);
    }

    public function test_rejects_expired_timestamp(): void
    {
        $signer = $this->app->make(Signer::class);
        $timestamp = (string) (time() - 120);
        $body = json_encode([]);

        $this->postJson('/test/endpoint', [], [
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signer->sign('POST', '/test/endpoint', $timestamp, $body),
            'X-Service-Name' => 'test-service',
        ])
            ->assertStatus(401);
    }
}
