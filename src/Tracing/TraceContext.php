<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tracing;

use Illuminate\Http\Request;

/**
 * W3C Trace Context headers for an outgoing request, continuing the trace of the incoming one.
 */
final class TraceContext
{
    /** @return array<string, string> */
    public static function headers(?Request $incoming): array
    {
        $traceparent = $incoming?->header('traceparent');

        $headers = ['traceparent' => is_string($traceparent) && $traceparent !== ''
            ? self::childOf($traceparent)
            : self::root()];

        $tracestate = $incoming?->header('tracestate');

        if (is_string($tracestate) && $tracestate !== '') {
            $headers['tracestate'] = $tracestate;
        }

        return $headers;
    }

    /**
     * Keep the trace id and flags of a traceparent, under a new span id.
     */
    private static function childOf(string $traceparent): string
    {
        $parts = explode('-', $traceparent);

        if (count($parts) !== 4) {
            return self::root();
        }

        return "$parts[0]-$parts[1]-".bin2hex(random_bytes(8))."-$parts[3]";
    }

    private static function root(): string
    {
        return '00-'.bin2hex(random_bytes(16)).'-'.bin2hex(random_bytes(8)).'-00';
    }
}
