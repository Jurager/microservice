<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Illuminate\Http\Request;
use Jurager\Microservice\Support\Certificate;
use Jurager\Microservice\Support\Ecdsa;
use Jurager\Microservice\Support\Signer;
use Jurager\Microservice\Tests\TestCase;
use RuntimeException;

class SignerTest extends TestCase
{
    private Signer $signer;

    private string $certificate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signer = $this->app->make(Signer::class);
        $this->certificate = (string) config('microservice.signing.certificate');
    }

    public function test_sign_produces_a_signature_the_signer_can_verify(): void
    {
        $timestamp = (string) time();

        $signature = $this->signer->sign('GET', '/api/orders', $timestamp, '');

        $request = Request::create('/api/orders', 'GET');

        $this->assertTrue($this->signer->verify($request, $signature, $timestamp, $this->certificate, 'test-service'));
    }

    public function test_sign_normalizes_path_with_leading_slash(): void
    {
        // ECDSA signatures are non-deterministic (a random nonce per call),
        // so equivalence is asserted by verifying, not by comparing bytes.
        $timestamp = (string) time();
        $signature = $this->signer->sign('POST', 'api/orders', $timestamp, '');

        $request = Request::create('/api/orders', 'POST');

        $this->assertTrue($this->signer->verify($request, $signature, $timestamp, $this->certificate, 'test-service'));
    }

    public function test_sign_includes_body_in_payload(): void
    {
        $withBody = $this->signer->sign('POST', '/api/orders', '1700000000', '{"product_id":1}');
        $withoutBody = $this->signer->sign('POST', '/api/orders', '1700000000', '');

        $this->assertNotSame($withBody, $withoutBody);
    }

    public function test_sign_uppercases_method(): void
    {
        $timestamp = (string) time();
        $signature = $this->signer->sign('get', '/api/orders', $timestamp, '');

        $request = Request::create('/api/orders', 'GET');

        $this->assertTrue($this->signer->verify($request, $signature, $timestamp, $this->certificate, 'test-service'));
    }

    public function test_verify_returns_true_for_valid_signature(): void
    {
        $timestamp = (string) time();
        $body = '{"product_id":1}';

        $signature = $this->signer->sign('POST', '/api/orders', $timestamp, $body);

        $request = Request::create('/api/orders', 'POST', [], [], [], [], $body);

        $this->assertTrue($this->signer->verify($request, $signature, $timestamp, $this->certificate, 'test-service'));
    }

    public function test_verify_rejects_expired_timestamp(): void
    {
        $timestamp = (string) (time() - 120);
        $signature = $this->signer->sign('GET', '/api/orders', $timestamp, '');

        $request = Request::create('/api/orders', 'GET');

        $this->assertFalse($this->signer->verify($request, $signature, $timestamp, $this->certificate, 'test-service'));
    }

    public function test_verify_rejects_wrong_signature(): void
    {
        $timestamp = (string) time();
        $request = Request::create('/api/orders', 'GET');

        $this->assertFalse($this->signer->verify($request, base64_encode('invalid-signature'), $timestamp, $this->certificate, 'test-service'));
    }

    public function test_verify_rejects_certificate_for_a_different_service(): void
    {
        // A validly-issued certificate, just not for the service the
        // request claims to be — must not be accepted for it.
        $timestamp = (string) time();
        $signature = $this->signer->sign('GET', '/api/orders', $timestamp, '');

        $request = Request::create('/api/orders', 'GET');

        $this->assertFalse($this->signer->verify($request, $signature, $timestamp, $this->certificate, 'some-other-service'));
    }

    public function test_verify_rejects_certificate_not_signed_by_the_configured_ca(): void
    {
        $timestamp = (string) time();
        $signature = $this->signer->sign('GET', '/api/orders', $timestamp, '');

        $rogueCa = self::generateKeyPair();
        $forgedCertificate = Certificate::issue('test-service', $this->signer->publicKey(), Ecdsa::loadPrivateKey($rogueCa['private']))->encode();

        $request = Request::create('/api/orders', 'GET');

        $this->assertFalse($this->signer->verify($request, $signature, $timestamp, $forgedCertificate, 'test-service'));
    }

    public function test_verify_accepts_a_different_service_certified_by_the_same_ca(): void
    {
        $peerKeys = self::generateKeyPair();
        $peerCertificate = $this->issueCertificateFor('billing', $peerKeys['public']);

        $peerSigner = new Signer(privateKey: $peerKeys['private']);
        $timestamp = (string) time();
        $signature = $peerSigner->sign('GET', '/api/orders', $timestamp, '');

        $request = Request::create('/api/orders', 'GET');

        $this->assertTrue($this->signer->verify($request, $signature, $timestamp, $peerCertificate, 'billing'));
    }

    public function test_verify_multipart_uses_empty_body_for_signature(): void
    {
        $timestamp = (string) time();

        $signature = $this->signer->sign('POST', '/api/import', $timestamp, '');

        $request = Request::create('/api/import', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=----WebKitFormBoundary',
        ], '--raw multipart body that should be ignored--');

        $this->assertTrue($this->signer->verify($request, $signature, $timestamp, $this->certificate, 'test-service'));
    }

    public function test_sign_treats_decoded_and_encoded_paths_as_the_same(): void
    {
        $timestamp = (string) time();
        $slug = 'Кресло Мягкое';

        $signature = $this->signer->sign('GET', "/api/products/$slug", $timestamp, '');

        $request = Request::create('/api/products/'.rawurlencode($slug), 'GET');

        $this->assertTrue($this->signer->verify($request, $signature, $timestamp, $this->certificate, 'test-service'));
    }

    public function test_sign_keeps_an_encoded_slash_distinct_from_a_separator(): void
    {
        $timestamp = (string) time();
        $signature = $this->signer->sign('GET', '/api/tags/a%2Fb', $timestamp, '');

        $request = Request::create('/api/tags/a/b', 'GET');

        $this->assertFalse($this->signer->verify($request, $signature, $timestamp, $this->certificate, 'test-service'));
    }

    public function test_verify_accepts_a_path_that_was_encoded_on_the_wire(): void
    {
        $timestamp = (string) time();
        $slug = 'Кресло Мягкое';

        // The gateway signs the path with route parameters substituted and decoded.
        $signature = $this->signer->sign('GET', "/api/products/$slug", $timestamp, '');

        // The receiving service sees the percent-encoded form the HTTP client sent.
        $request = Request::create('/api/products/'.rawurlencode($slug), 'GET');

        $this->assertTrue($this->signer->verify($request, $signature, $timestamp, $this->certificate, 'test-service'));
    }

    public function test_verify_non_multipart_uses_raw_body(): void
    {
        $timestamp = (string) time();
        $body = '{"import_type":"products"}';

        $signature = $this->signer->sign('POST', '/api/import', $timestamp, $body);

        $request = Request::create('/api/import', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $this->assertTrue($this->signer->verify($request, $signature, $timestamp, $this->certificate, 'test-service'));
    }

    public function test_verify_defaults_service_to_x_service_name_header(): void
    {
        $timestamp = (string) time();
        $signature = $this->signer->sign('GET', '/api/orders', $timestamp, '');

        $request = Request::create('/api/orders', 'GET', server: [
            'HTTP_X_SERVICE_NAME' => 'test-service',
        ]);

        $this->assertTrue($this->signer->verify($request, $signature, $timestamp, $this->certificate));
    }

    public function test_public_key_matches_the_one_certified(): void
    {
        $this->assertSame(
            Certificate::decode($this->certificate)->publicKey,
            $this->signer->publicKey(),
        );
    }

    public function test_verify_own_certificate_returns_true_when_consistent(): void
    {
        $this->assertTrue($this->signer->verifyOwnCertificate());
    }

    public function test_verify_own_certificate_returns_false_when_certificate_does_not_match_the_private_key(): void
    {
        $mismatched = self::generateKeyPair();
        $wrongCertificate = $this->issueCertificateFor('test-service', $mismatched['public']);

        $signer = new Signer(
            privateKey: config('microservice.signing.private_key'),
            certificate: $wrongCertificate,
            caPublicKey: config('microservice.signing.ca_public_key'),
        );

        $this->assertFalse($signer->verifyOwnCertificate());
    }

    public function test_sign_raw_throws_without_a_configured_key(): void
    {
        $signer = new Signer(privateKey: '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SERVICE_PRIVATE_KEY is not configured');

        $signer->signRaw('anything');
    }

    public function test_sign_raw_returns_placeholder_without_a_configured_key_in_debug_mode(): void
    {
        // In debug mode the receiving side never inspects the signature, so
        // signing without a key is harmless rather than a real error.
        config()->set('microservice.debug', true);

        $signer = new Signer(privateKey: '');

        $this->assertSame('', $signer->signRaw('anything'));
        $this->assertNull($signer->publicKey());
    }

    public function test_assert_configured_passes_when_consistent(): void
    {
        $this->signer->assertConfigured();

        $this->addToAssertionCount(1);
    }

    public function test_assert_configured_throws_without_a_private_key(): void
    {
        $signer = new Signer(privateKey: '', certificate: $this->certificate, caPublicKey: config('microservice.signing.ca_public_key'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SERVICE_PRIVATE_KEY is not configured');

        $signer->assertConfigured();
    }

    public function test_assert_configured_throws_when_certificate_does_not_match(): void
    {
        $mismatched = self::generateKeyPair();
        $wrongCertificate = $this->issueCertificateFor('test-service', $mismatched['public']);

        $signer = new Signer(
            privateKey: config('microservice.signing.private_key'),
            certificate: $wrongCertificate,
            caPublicKey: config('microservice.signing.ca_public_key'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match');

        $signer->assertConfigured();
    }
}
