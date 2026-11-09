<?php

declare(strict_types=1);

namespace Jurager\Microservice\Signing;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Request;
use Illuminate\Support\InteractsWithTime;
use Jurager\Microservice\Tracing\RequestId;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Sign outgoing traffic and verify peers using CA-issued certificates.
 */
class Signer
{
    use InteractsWithTime;

    private const int ALGO = OPENSSL_ALGO_SHA256;

    private ?OpenSSLAsymmetricKey $privateKey = null;

    private ?string $certificate = null;

    private ?OpenSSLAsymmetricKey $caPublicKey = null;

    /**
     * Whether the configuration was found consistent, checked when this service first signs.
     */
    private bool $verified = false;

    /**
     * @param  string|null  $privateKey  This service's base64-wrapped PEM private key.
     * @param  string|null  $certificate  This service's certificate, issued by the CA.
     * @param  string|null  $caPublicKey  The cluster CA's base64-wrapped PEM public key.
     * @param  int  $tolerance  Most seconds a signature may be old.
     * @param  bool  $enabled  Sign and verify at all; when false nothing is signed — local development only.
     */
    public function __construct(
        #[Config('microservice.signing.private_key')] ?string $privateKey = null,
        #[Config('microservice.signing.certificate')] ?string $certificate = null,
        #[Config('microservice.signing.ca_public_key')] ?string $caPublicKey = null,
        #[Config('microservice.timestamp_tolerance', 60)] private readonly int $tolerance = 60,
        #[Config('microservice.signing.enabled', true)] private readonly bool $enabled = true,
    ) {
        if ($privateKey !== null && $privateKey !== '') {
            $this->privateKey = Ecdsa::loadPrivateKey($privateKey);
        }

        $this->certificate = $certificate !== '' ? $certificate : null;

        if ($caPublicKey !== null && $caPublicKey !== '') {
            $this->caPublicKey = Ecdsa::loadPublicKey($caPublicKey);
        }
    }

    /**
     * Whether traffic is signed and verified — false only for local development.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * This service's own certificate, attached to every outgoing request.
     */
    public function certificate(): ?string
    {
        return $this->certificate;
    }

    /**
     * Validate the signing key, certificate, and CA public key.
     */
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

        $cert = Certificate::decode($this->certificate);
        $ownPublicKey = Ecdsa::publicKeyFor($this->privateKey);

        if ($cert === null || $cert->publicKey !== $ownPublicKey || ! $cert->verify($this->caPublicKey)) {
            throw new RuntimeException('SERVICE_CERTIFICATE does not match SERVICE_PRIVATE_KEY, or was not signed by SERVICE_CA_PUBLIC_KEY. Re-issue it with `microservice:certificate:issue`.');
        }
    }

    /**
     * Sign an outgoing request, including its query string and request ID.
     */
    public function sign(string $method, string $target, string $timestamp, ?string $body = null, ?string $requestId = null): string
    {
        [$path, $query] = array_pad(explode('?', $target, 2), 2, '');

        return $this->signRaw(self::payload($method, $path, $query, $timestamp, $body ?? '', $requestId ?? ''));
    }

    /**
     * Build the versioned payload covered by a request signature.
     */
    private static function payload(string $method, string $path, string $query, string $timestamp, string $body, string $requestId): string
    {
        return "v3\n".strtoupper($method)."\n".self::canonicalTarget($path, $query)."\n$timestamp\n$requestId\n$body";
    }

    private static function canonicalTarget(string $path, string $query): string
    {
        $query = self::canonicalQuery($query);

        return self::canonicalPath($path).($query !== '' ? "?$query" : '');
    }

    /**
     * Canonical form of a request path.
     */
    private static function canonicalPath(string $path): string
    {
        $segments = array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', trim($path, '/')),
        );

        return '/'.implode('/', $segments);
    }

    /**
     * Canonicalize query pairs while preserving the order of repeated keys.
     */
    private static function canonicalQuery(string $query): string
    {
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            $pairs[] = [self::encode($key), self::encode($value)];
        }

        usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return implode('&', array_map(static fn (array $pair): string => $pair[0].'='.$pair[1], $pairs));
    }

    private static function encode(string $component): string
    {
        return rawurlencode(rawurldecode(str_replace('+', ' ', $component)));
    }

    /**
     * Verify a request signature using the sender's certificate.
     *
     * @throws RuntimeException when no CA public key is configured — nothing could ever be verified
     */
    public function verify(Request $request, string $signature, string $timestamp, string $certificate, ?string $service = null): bool
    {
        if ($this->caPublicKey === null) {
            throw new RuntimeException('Cannot verify signatures: SERVICE_CA_PUBLIC_KEY is not configured.');
        }

        if (abs($this->currentTime() - (int) $timestamp) > $this->tolerance) {
            return false;
        }

        $service ??= $request->header('X-Service-Name');

        if (! is_string($service) || $service === '') {
            return false;
        }

        $contentType = (string) $request->header('Content-Type', '');
        $body = stripos($contentType, 'multipart/form-data') !== false ? '' : $request->getContent();
        $path = '/'.ltrim($request->path(), '/');
        $query = (string) $request->server->get('QUERY_STRING', '');
        $requestId = (string) $request->header('X-Request-Id', '');

        return array_any(
            array_unique([$requestId, RequestId::original($requestId)]),
            fn (string $id): bool => $this->verifyRaw(self::payload($request->method(), $path, $query, $timestamp, $body, $id), $signature, $certificate, $service),
        );
    }

    /**
     * Sign a payload, returning an empty string when signing is disabled.
     */
    public function signRaw(string $payload): string
    {
        if (! $this->enabled) {
            return '';
        }

        // Checked once, when the service first signs, rather than on every boot.
        if (! $this->verified) {
            $this->assertConfigured();
            $this->verified = true;
        }

        if (! openssl_sign($payload, $signature, $this->privateKey, self::ALGO)) {
            throw new RuntimeException('Failed to sign payload: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        return base64_encode($signature);
    }

    /**
     * Verify a payload signature and its certified service name.
     */
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

    /**
     * Resolve $certificate's public key, provided it's signed by the CA and certifies $expectedService.
     */
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
