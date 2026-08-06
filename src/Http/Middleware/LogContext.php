<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogContext
{
    public function handle(Request $request, Closure $next): Response
    {
        Log::withContext(array_filter([
            'request_id' => $this->requestId($request),
            'trace_id'   => $this->traceId($request),
        ]));

        return $next($request);
    }

    private function requestId(Request $request): string
    {
        $requestId = $request->header('X-Request-Id');

        return is_string($requestId) && Str::isUuid($requestId)
            ? $requestId
            : (string) Str::uuid();
    }

    private function traceId(Request $request): ?string
    {
        $traceparent = $request->header('traceparent');

        if (! is_string($traceparent)) {
            return null;
        }

        $traceId = explode('-', $traceparent)[1] ?? '';

        return preg_match('/^[0-9a-f]{32}$/', $traceId) === 1 ? $traceId : null;
    }
}
