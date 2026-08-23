<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Http\Request;

final class LinkRewriter
{
    /** Rewrite all links to current request scheme and host. */
    public static function rewriteAll(array $links): array
    {
        $request = self::currentRequest();

        if ($request === null) {
            return $links;
        }

        $result = [];

        foreach ($links as $key => $link) {
            $result[$key] = self::rewrite($request, $link);
        }

        return $result;
    }

    /** Rewrite single link scheme and host. */
    public static function rewrite(Request $request, mixed $link): mixed
    {
        if (! is_string($link) || $link === '') {
            return $link;
        }

        $parsed = parse_url($link);

        if ($parsed === false) {
            return $link;
        }

        $path = $parsed['path'] ?? '';
        $query = $parsed['query'] ?? null;

        parse_str((string) $query, $params);

        if (isset($params['page'])) {
            return $request->fullUrlWithQuery(['page' => $params['page']]);
        }

        return $request->getSchemeAndHttpHost().$path.($query ? "?{$query}" : '');
    }

    /** Get current HTTP request instance. */
    public static function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return $request instanceof Request ? $request : null;
    }
}
