<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jurager\Microservice\Support\HmacSigner;
use Symfony\Component\HttpFoundation\Response;

class TrustGateway
{
    public function __construct(
        protected readonly HmacSigner $signer
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (config('microservice.debug')) {
            return $next($request);
        }

        $signature = $request->header('X-Signature');
        $timestamp = $request->header('X-Timestamp');

        if ($signature === null || $timestamp === null) {
            return response()->json(['error' => 'Missing signature headers.'], 401);
        }

        if (!$this->signer->verify($request, $signature, $timestamp)) {
            return response()->json(['error' => 'Invalid signature or timestamp.'], 401);
        }

        return $next($request);
    }
}
