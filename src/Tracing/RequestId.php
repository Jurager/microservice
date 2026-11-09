<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tracing;

/**
 * Request IDs as their senders generated them.
 */
final class RequestId
{
    /**
     * What Envoy writes over the version nibble of a request ID it traces.
     */
    private const array TRACE_MARKS = ['9', 'a', 'b'];

    /**
     * Undo the trace mark an Envoy proxy, such as an Istio sidecar, puts into a UUID v4 request ID on the way.
     */
    public static function original(string $requestId): string
    {
        return strlen($requestId) === 36 && in_array($requestId[14], self::TRACE_MARKS, true)
            ? substr_replace($requestId, '4', 14, 1)
            : $requestId;
    }
}
