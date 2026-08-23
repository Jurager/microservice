<?php

declare(strict_types=1);

namespace Jurager\Microservice\Support;

use JsonException;
use OpenSSLAsymmetricKey;
use RuntimeException;

final class Certificate
{
    private const int ALGO = OPENSSL_ALGO_SHA256;

    private function __construct(
        public readonly string $service,
        public readonly string $publicKey,
        public readonly string $issuedAt,
        public readonly string $signature,
    ) {
    }

    /** Issue a certificate binding $service to $publicKey, signed by the CA. */
    public static function issue(string $service, string $publicKey, OpenSSLAsymmetricKey $caPrivateKey, ?string $issuedAt = null): self
    {
        $issuedAt ??= gmdate('Y-m-d\TH:i:s\Z');

        if (! openssl_sign(self::canonicalize($service, $publicKey, $issuedAt), $signature, $caPrivateKey, self::ALGO)) {
            throw new RuntimeException('Failed to issue certificate: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        return new self($service, $publicKey, $issuedAt, base64_encode($signature));
    }

    /** Verify this certificate was signed by the given CA. */
    public function verify(OpenSSLAsymmetricKey $caPublicKey): bool
    {
        $decoded = base64_decode($this->signature, true);

        if (! is_string($decoded) || $decoded === '') {
            return false;
        }

        return openssl_verify(self::canonicalize($this->service, $this->publicKey, $this->issuedAt), $decoded, $caPublicKey, self::ALGO) === 1;
    }

    /**
     * Base64-wrapped JSON form, safe to transport in a header or store as plain config.
     *
     * @throws JsonException
     */
    public function encode(): string
    {
        return base64_encode(json_encode([
            'service' => $this->service,
            'public_key' => $this->publicKey,
            'issued_at' => $this->issuedAt,
            'signature' => $this->signature,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Parse an encoded certificate, or null for anything malformed — must never throw on attacker input. */
    public static function decode(string $encoded): ?self
    {
        $json = base64_decode($encoded, true);

        if (! is_string($json) || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            return null;
        }

        if (array_any(['service', 'public_key', 'issued_at', 'signature'], fn ($key) => ! isset($data[$key]) || ! is_string($data[$key]) || $data[$key] === '')) {
            return null;
        }

        return new self($data['service'], $data['public_key'], $data['issued_at'], $data['signature']);
    }

    private static function canonicalize(string $service, string $publicKey, string $issuedAt): string
    {
        return "$service\n$publicKey\n$issuedAt";
    }
}
