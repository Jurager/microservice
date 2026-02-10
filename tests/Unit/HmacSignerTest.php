<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Illuminate\Http\Request;
use Jurager\Microservice\Support\HmacSigner;
use Jurager\Microservice\Tests\TestCase;

class HmacSignerTest extends TestCase
{
    private HmacSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = new HmacSigner();
    }

    public function test_sign_produces_expected_hmac(): void
    {
        $signature = $this->signer->sign('GET', '/api/orders', '1700000000', '');

        $expected = hash_hmac('sha256', "GET\n/api/orders\n1700000000\n", 'test-secret-key');

        $this->assertSame($expected, $signature);
    }

    public function test_sign_normalizes_path_with_leading_slash(): void
    {
        $a = $this->signer->sign('POST', 'api/orders', '1700000000', '');
        $b = $this->signer->sign('POST', '/api/orders', '1700000000', '');

        $this->assertSame($a, $b);
    }

    public function test_sign_includes_body_in_payload(): void
    {
        $withBody = $this->signer->sign('POST', '/api/orders', '1700000000', '{"product_id":1}');
        $withoutBody = $this->signer->sign('POST', '/api/orders', '1700000000', '');

        $this->assertNotSame($withBody, $withoutBody);
    }

    public function test_sign_uppercases_method(): void
    {
        $lower = $this->signer->sign('get', '/api/orders', '1700000000', '');
        $upper = $this->signer->sign('GET', '/api/orders', '1700000000', '');

        $this->assertSame($lower, $upper);
    }

    public function test_verify_returns_true_for_valid_signature(): void
    {
        $timestamp = (string) time();
        $body = '{"product_id":1}';

        $signature = $this->signer->sign('POST', '/api/orders', $timestamp, $body);

        $request = Request::create('/api/orders', 'POST', [], [], [], [], $body);

        $this->assertTrue($this->signer->verify($request, $signature, $timestamp));
    }

    public function test_verify_rejects_expired_timestamp(): void
    {
        $timestamp = (string) (time() - 120);
        $signature = $this->signer->sign('GET', '/api/orders', $timestamp, '');

        $request = Request::create('/api/orders', 'GET');

        $this->assertFalse($this->signer->verify($request, $signature, $timestamp));
    }

    public function test_verify_rejects_wrong_signature(): void
    {
        $timestamp = (string) time();
        $request = Request::create('/api/orders', 'GET');

        $this->assertFalse($this->signer->verify($request, 'invalid-signature', $timestamp));
    }
}
