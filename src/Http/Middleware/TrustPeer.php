<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jurager\Microservice\Exceptions\InvalidSignatureException;
use Jurager\Microservice\Exceptions\MissingCertificateException;
use Jurager\Microservice\Exceptions\MissingServiceNameException;
use Jurager\Microservice\Exceptions\MissingSignatureException;
use Jurager\Microservice\Signing\Signer;
use Symfony\Component\HttpFoundation\Response;

class TrustPeer
{
    /**
     * Request attribute holding the name of the verified peer service.
     */
    public const string ATTRIBUTE = 'microservice.peer';

    public function __construct(protected readonly Signer $signer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $service = $request->header('X-Service-Name');

        // With signing switched off — local development only — every request is let through.
        if ($this->signer->enabled()) {
            $this->verify($request, $service);
        }

        if (is_string($service) && $service !== '') {
            $request->attributes->set(self::ATTRIBUTE, $service);
        }

        return $next($request);
    }

    /**
     * The peer service the request was verified for, or null when it did not pass this middleware.
     */
    public static function peer(Request $request): ?string
    {
        $peer = $request->attributes->get(self::ATTRIBUTE);

        return is_string($peer) ? $peer : null;
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
