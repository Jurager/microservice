<?php

declare(strict_types=1);

namespace Jurager\Microservice\Support;

use Illuminate\Http\Request;
use OpenSSLAsymmetricKey;
use RuntimeException;

class Signer
{
    private const int ALGO = OPENSSL_ALGO_SHA256;

    private ?OpenSSLAsymmetricKey $privateKey = null;

    private ?string $certificate = null;

    private ?OpenSSLAsymmetricKey $caPublicKey = null;

    private int $tolerance;

    public function __construct(
        ?string $privateKey = null,
        ?string $certificate = null,
        ?string $caPublicKey = null,
        ?int $tolerance = null,
    ) {
        $privateKey ??= (string) config('microservice.signing.private_key', '');
        $certificate ??= (string) config('microservice.signing.certificate', '');
        $caPublicKey ??= (string) config('microservice.signing.ca_public_key', '');
        $this->tolerance = $tolerance ?? (int) config('microservice.timestamp_tolerance', 60);

        if ($privateKey !== '') {
            $this->privateKey = Ecdsa::loadPrivateKey($privateKey);
        }

        $this->certificate = $certificate !== '' ? $certificate : null;

        if ($caPublicKey !== '') {
            $this->caPublicKey = Ecdsa::loadPublicKey($caPublicKey);
        }
    }

    /** This service's own public key (PEM, base64-wrapped), derived from its private key. */
    public function publicKey(): ?string
    {
        if ($this->privateKey === null) {
            return null;
        }

        return base64_encode(openssl_pkey_get_details($this->privateKey)['key']);
    }

    /** This service's own certificate. */
    public function certificate(): ?string
    {
        return $this->certificate;
    }

    /** Whether our own certificate is signed by the configured CA and certifies our own public key. */
    public function verifyOwnCertificate(): bool
    {
        if ($this->certificate === null || $this->caPublicKey === null || $this->privateKey === null) {
            return false;
        }

        $cert = Certificate::decode($this->certificate);

        return $cert !== null
            && $cert->verify($this->caPublicKey)
            && $cert->publicKey === $this->publicKey();
    }

    /** Throws unless a private key, a certificate, and a CA public key are all present and consistent. */
    public function assertConfigured(): void
    {
        if ($this->privateKey === null) {
            throw new RuntimeException('SERVICE_PRIVATE_KEY is not configured. Generate one with `php artisan microservice:keygen`.');
        }

        if ($this->certificate === null) {
            throw new RuntimeException('SERVICE_CERTIFICATE is not configured. Issue one with `php artisan microservice:certificate:issue`.');
        }

        if ($this->caPublicKey === null) {
            throw new RuntimeException('SERVICE_CA_PUBLIC_KEY is not configured.');
        }

        if (! $this->verifyOwnCertificate()) {
            throw new RuntimeException('SERVICE_CERTIFICATE does not match SERVICE_PRIVATE_KEY, or was not signed by SERVICE_CA_PUBLIC_KEY. Re-issue it with `microservice:certificate:issue`.');
        }
    }

    /** Produce an ECDSA signature for an outgoing request. */
    public function sign(string $method, string $path, string $timestamp, ?string $body = null): string
    {
        return $this->signRaw(strtoupper($method)."\n".$this->normalizePath($path)."\n$timestamp\n".($body ?? ''));
    }

    /** Canonical form of a request path. */
    private function normalizePath(string $path): string
    {
        $segments = array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', trim($path, '/')),
        );

        return '/'.implode('/', $segments);
    }

    /** Verify an incoming request against the public key certified for $service (or the X-Service-Name header). */
    public function verify(Request $request, string $signature, string $timestamp, string $certificate, ?string $service = null): bool
    {
        if (abs(time() - (int) $timestamp) > $this->tolerance) {
            return false;
        }

        $service ??= $request->header('X-Service-Name');

        if (! is_string($service) || $service === '') {
            return false;
        }

        $contentType = $request->header('Content-Type', '');
        $isMultipart = stripos($contentType, 'multipart/form-data') !== false;

        $payload = strtoupper($request->method())."\n"
            .$this->normalizePath('/'.ltrim($request->path(), '/'))."\n"
            ."$timestamp\n"
            .($isMultipart ? '' : $request->getContent());

        return $this->verifyRaw($payload, $signature, $certificate, $service);
    }

    /**
     * Produce an ECDSA signature for an arbitrary payload string.
     *
     * Without a key, returns '' in debug mode; throws otherwise.
     */
    public function signRaw(string $payload): string
    {
        if ($this->privateKey === null) {
            if (config('microservice.debug', false) === true) {
                return '';
            }

            throw new RuntimeException('Cannot sign: SERVICE_PRIVATE_KEY is not configured. Generate one with `php artisan microservice:keygen`.');
        }

        if (! openssl_sign($payload, $signature, $this->privateKey, self::ALGO)) {
            throw new RuntimeException('Failed to sign payload: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        return base64_encode($signature);
    }

    /** Verify a payload signature against $certificate, provided it's signed by the CA and certifies $expectedService. */
    public function verifyRaw(string $payload, string $signature, string $certificate, string $expectedService): bool
    {
        $publicKey = $this->publicKeyFromCertificate($certificate, $expectedService);

        if ($publicKey === null) {
            return false;
        }

        $decoded = base64_decode($signature, true);

        if (! is_string($decoded) || $decoded === '') {
            return false;
        }

        return openssl_verify($payload, $decoded, $publicKey, self::ALGO) === 1;
    }

    /** Resolve $certificate's public key, if it's signed by the CA and certifies $expectedService. */
    private function publicKeyFromCertificate(string $certificate, string $expectedService): ?OpenSSLAsymmetricKey
    {
        if ($this->caPublicKey === null) {
            return null;
        }

        $cert = Certificate::decode($certificate);

        if ($cert === null || $cert->service !== $expectedService || ! $cert->verify($this->caPublicKey)) {
            return null;
        }

        try {
            return Ecdsa::loadPublicKey($cert->publicKey);
        } catch (RuntimeException) {
            return null;
        }
    }
}
