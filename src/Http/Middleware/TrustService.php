<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jurager\Microservice\Exceptions\MissingServiceNameException;
use Symfony\Component\HttpFoundation\Response;

class TrustService extends TrustGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('X-Service-Name') === null) {
            throw new MissingServiceNameException();
        }

        return parent::handle($request, $next);
    }
}
