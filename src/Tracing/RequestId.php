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
     * The request ID of an operation: the same key always gives the same UUID v4.
     */
    public static function for(string $key): string
    {
        $hash = substr(hash('sha256', $key), 0, 32);
        $hash[12] = '4';
        $hash[16] = dechex(hexdec($hash[16]) & 0x3 | 0x8);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hash, 4));
    }

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
