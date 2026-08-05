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
            'request_id' => $request->header('X-Request-Id') ?? (string) Str::uuid(),
            'trace_id'   => $this->traceId($request),
        ]));

        return $next($request);
    }

    /** Extract the trace-id segment from an incoming W3C traceparent header. */
    private function traceId(Request $request): ?string
    {
        $traceparent = $request->header('traceparent');

        if (! is_string($traceparent)) {
            return null;
        }

        $parts = explode('-', $traceparent);

        return $parts[1] ?? null;
    }
}
