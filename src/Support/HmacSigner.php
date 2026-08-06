<?php

declare(strict_types=1);

namespace Jurager\Microservice\Support;

use Illuminate\Http\Request;

class HmacSigner
{
    public function __construct(
        private ?string $algorithm = null,
        private ?string $secret = null,
        private ?int $tolerance = null,
    ) {
        $this->algorithm ??= config('microservice.algorithm', 'sha256');
        $this->secret ??= config('microservice.secret', '');
        $this->tolerance ??= (int) config('microservice.timestamp_tolerance', 60);
    }

    /**
     * Produce an HMAC signature for an outgoing request.
     */
    public function sign(string $method, string $path, string $timestamp, ?string $body = null): string
    {
        $payload = strtoupper($method)."\n".$this->normalizePath($path)."\n$timestamp\n".($body ?? '');

        return hash_hmac($this->algorithm, $payload, $this->secret);
    }

    /**
     * Canonical form of a request path.
     */
    private function normalizePath(string $path): string
    {
        $segments = array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', trim($path, '/')),
        );

        return '/'.implode('/', $segments);
    }

    /**
     * Verify the HMAC signature of an incoming request.
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
     */
    public function signRaw(string $payload): string
    {
        return hash_hmac($this->algorithm, $payload, $this->secret);
    }

    /**
     * Verify an HMAC signature for an arbitrary payload string.
     */
    public function verifyRaw(string $payload, string $signature): bool
    {
        return hash_equals($this->signRaw($payload), $signature);
    }
}
