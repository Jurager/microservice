<?php

declare(strict_types=1);

namespace Jurager\Microservice\Support;

use Illuminate\Http\Request;

class HmacSigner
{
    private string $algorithm;

    private string $secret;

    private int $tolerance;

    public function __construct()
    {
        $this->algorithm = config('microservice.algorithm', 'sha256');
        $this->secret = (string) config('microservice.secret', '');
        $this->tolerance = (int) config('microservice.timestamp_tolerance', 60);
    }

    /**
     * Produce an HMAC signature for an outgoing request.
     *
     * Signed payload format (newline-separated):
     *   METHOD\n/path\ntimestamp\nbody
     *
     * Multipart requests pass an empty string for $body because the boundary
     * embedded in the Content-Type header changes per request and cannot be
     * reproduced reliably on the receiving side.
     */
    public function sign(string $method, string $path, string $timestamp, ?string $body = null): string
    {
        // Normalize the path - include the leading slash but omit the trailing one
        // So that the client's URL matches the server's regardless of slashes
        $normalizedPath = '/'.trim($path, '/');

        $payload = strtoupper($method)."\n".$normalizedPath."\n$timestamp\n".($body ?? '');

        return hash_hmac($this->algorithm, $payload, $this->secret);
    }

    /**
     * Verify the HMAC signature of an incoming request.
     *
     * Returns false if the timestamp is outside the tolerance window or if the
     * signature does not match. Uses hash_equals to prevent timing attacks.
     */
    public function verify(Request $request, string $signature, string $timestamp): bool
    {
        if (abs(time() - (int) $timestamp) > $this->tolerance) {
            return false;
        }

        $contentType = $request->header('Content-Type', '');
        $isMultipart = stripos($contentType, 'multipart/form-data') !== false;

        $expected = $this->sign(
            $request->method(),
            '/'.ltrim($request->path(), '/'),
            $timestamp,
            $isMultipart ? '' : $request->getContent()
        );

        return hash_equals($expected, $signature);
    }

    /**
     * Produce an HMAC signature for an arbitrary payload string.
     * Used by MessageBus to sign event envelopes.
     */
    public function signRaw(string $payload): string
    {
        return hash_hmac($this->algorithm, $payload, $this->secret);
    }

    /**
     * Verify an HMAC signature for an arbitrary payload string.
     * Uses hash_equals to prevent timing attacks.
     */
    public function verifyRaw(string $payload, string $signature): bool
    {
        return hash_equals($this->signRaw($payload), $signature);
    }
}
