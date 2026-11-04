<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Jurager\Microservice\Tracing\RequestTiming;
use Symfony\Component\HttpFoundation\Response;

/**
 * Add request and trace IDs to log context and report request timings.
 */
class LogContext
{
    public function __construct(private readonly RequestTiming $timing) {}

    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);

        $this->timing->flush();

        Context::add(array_filter([
            'request_id' => $this->requestId($request),
            'trace_id' => $this->traceId($request),
            'http_method' => $request->method(),
            'http_route' => $request->route()?->uri(),
        ]));

        $response = $next($request);

        if ($this->timing->enabled()) {
            $this->timing->add('app_total', (hrtime(true) - $started) / 1e6);

            $existing = $response->headers->get('Server-Timing');
            $response->headers->set('Server-Timing', $existing ? $existing.', '.$this->timing->header() : $this->timing->header());
        }

        return $response;
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

        if (! is_string($traceparent) || ! preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', $traceparent, $matches)) {
            return null;
        }

        return $matches[1] !== str_repeat('0', 32) && $matches[2] !== str_repeat('0', 16) ? $matches[1] : null;
    }
}
