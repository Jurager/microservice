<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustService extends TrustGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('X-Service-Name') === null) {
            return response()->json(['error' => 'Missing service name header.'], 401);
        }

        return parent::handle($request, $next);
    }
}
