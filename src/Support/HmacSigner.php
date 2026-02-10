<?php

declare(strict_types=1);

namespace Jurager\Microservice\Support;

use Illuminate\Http\Request;

class HmacSigner
{
    public function sign(string $method, string $path, string $timestamp, ?string $body = null): string
    {
        $payload = strtoupper($method) . "\n" . '/' . ltrim($path, '/') . "\n$timestamp\n" . ($body ?? '');

        return hash_hmac(
            config('microservice.algorithm', 'sha256'),
            $payload,
            config('microservice.secret')
        );
    }

    public function verify(Request $request, string $signature, string $timestamp): bool
    {
        $tolerance = config('microservice.timestamp_tolerance', 60);

        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = $this->sign(
            $request->method(),
            '/' . ltrim($request->path(), '/'),
            $timestamp,
            $request->getContent()
        );

        return hash_equals($expected, $signature);
    }
}
