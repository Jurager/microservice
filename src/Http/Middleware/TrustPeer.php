<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jurager\Microservice\Exceptions\InvalidSignatureException;
use Jurager\Microservice\Exceptions\MissingCertificateException;
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
        $service = $request->header('X-Service-Name');

        if (! config('microservice.debug', false)) {
            $this->verify($request, $service);
        }

        return $next($request);
    }

    private function verify(Request $request, ?string $service): void
    {
        if ($service === null) {
            throw new MissingServiceNameException();
        }

        $signature = $request->header('X-Signature');
        $timestamp = $request->header('X-Timestamp');
        $certificate = $request->header('X-Service-Cert');

        if ($signature === null || $timestamp === null) {
            throw new MissingSignatureException();
        }

        if ($certificate === null) {
            throw new MissingCertificateException();
        }

        if (! $this->signer->verify(
            $request,
            $signature,
            $timestamp,
            $certificate,
            $service,
        )) {
            throw new InvalidSignatureException();
        }
    }

}
