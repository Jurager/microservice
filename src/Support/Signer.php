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

    private int $tolerance;

    private readonly PublicKeyResolver $resolver;

    public function __construct(
        ?PublicKeyResolver $resolver = null,
        ?string $privateKey = null,
        ?int $tolerance = null,
    ) {
        $this->resolver = $resolver ?? app(PublicKeyResolver::class);
        $privateKey ??= (string) config('microservice.signing.private_key', '');
        $this->tolerance = $tolerance ?? (int) config('microservice.timestamp_tolerance', 60);

        if ($privateKey !== '') {
            $this->privateKey = Ecdsa::loadPrivateKey($privateKey);
        }
    }

    /** This service's own public key (PEM, base64-wrapped), derived from its private key — published in its manifest. */
    public function publicKey(): ?string
    {
        return $this->privateKey !== null ? Ecdsa::publicKeyFor($this->privateKey) : null;
    }

    /** Throws unless a private key is configured. */
    public function assertConfigured(): void
    {
        if ($this->privateKey === null) {
            throw new RuntimeException('SERVICE_PRIVATE_KEY is not configured. Generate one with `php artisan microservice:keygen`.');
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

    /** Verify an incoming request's signature against the public key published in $service's manifest (or the X-Service-Name header). */
    public function verify(Request $request, string $signature, string $timestamp, ?string $service = null): bool
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

        return $this->verifyRaw($payload, $signature, $service);
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

    /** Verify a payload signature against $service's published public key, refetching once if a cached key no longer matches. */
    public function verifyRaw(string $payload, string $signature, string $service): bool
    {
        $publicKey = $this->resolver->resolve($service);

        if ($publicKey !== null && $this->verifyWithKey($payload, $signature, $publicKey)) {
            return true;
        }

        // The cached key may be stale if the peer rotated it — one live refetch
        // before giving up, rather than waiting out the manifest's cache TTL.
        $freshKey = $this->resolver->resolve($service, force: true);

        if ($freshKey === null || $freshKey === $publicKey) {
            return false;
        }

        return $this->verifyWithKey($payload, $signature, $freshKey);
    }

    private function verifyWithKey(string $payload, string $signature, string $publicKeyEncoded): bool
    {
        try {
            $publicKey = Ecdsa::loadPublicKey($publicKeyEncoded);
        } catch (RuntimeException) {
            return false;
        }

        $decoded = base64_decode($signature, true);

        if (! is_string($decoded) || $decoded === '') {
            return false;
        }

        return openssl_verify($payload, $decoded, $publicKey, self::ALGO) === 1;
    }
}
