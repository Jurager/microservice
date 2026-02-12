<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jurager\Microservice\Exceptions\InvalidSignatureException;
use Jurager\Microservice\Exceptions\MissingSignatureException;
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
            throw new MissingSignatureException();
        }

        if (! $this->signer->verify($request, $signature, $timestamp)) {
            throw new InvalidSignatureException();
        }

        return $next($request);
    }
}
