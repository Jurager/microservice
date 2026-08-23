<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jurager\Microservice\Exceptions\InvalidSignatureException;
use Jurager\Microservice\Exceptions\MissingServiceNameException;
use Jurager\Microservice\Exceptions\MissingSignatureException;
use Jurager\Microservice\Support\Signer;
use Symfony\Component\HttpFoundation\Response;

class TrustPeer
{
    public function __construct(
        protected readonly Signer $signer
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (config('microservice.debug', false)) {
            return $next($request);
        }

        $signature = $request->header('X-Signature');
        $timestamp = $request->header('X-Timestamp');

        if ($signature === null || $timestamp === null) {
            throw new MissingSignatureException();
        }

        $service = $request->header('X-Service-Name');

        if ($service === null) {
            throw new MissingServiceNameException();
        }

        if (! $this->signer->verify($request, $signature, $timestamp, $service)) {
            throw new InvalidSignatureException();
        }

        return $next($request);
    }
}
