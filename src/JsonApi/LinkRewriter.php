<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Http\Request;

final class LinkRewriter
{
    /**
     * Rewrite links of an upstream service to the scheme and host of the given request.
     */
    public static function rewriteAll(array $links, Request $request): array
    {
        $result = [];

        foreach ($links as $key => $link) {
            $result[$key] = self::rewrite($request, $link);
        }

        return $result;
    }

    /**
     * Rewrite single link scheme and host.
     */
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
}
