<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Symfony\Component\HttpFoundation\Response;

class JsonApiPagination
{
    public function handle(Request $request, Closure $next): Response
    {
        AbstractPaginator::currentPageResolver(
            static fn (): int => max(1, (int) $request->input('page.number', 1))
        );

        $path = $this->resolveCurrentPath($request);

        AbstractPaginator::currentPathResolver(static fn (): string => $path);

        $this->registerPaginationMacro();

        return $next($request);
    }

    /**
     * Resolve the pagination base URL, honoring reverse-proxy headers.
     */
    protected function resolveCurrentPath(Request $request): string
    {
        if ($gatewayUrl = $request->header('X-Gateway-Base-Url')) {
            return $gatewayUrl;
        }

        $host = $request->header('X-Forwarded-Host');

        if ($host === null) {
            return $request->url();
        }

        $scheme = $request->header('X-Forwarded-Proto', $request->getScheme());
        $prefix = trim((string) $request->header('X-Forwarded-Prefix'), '/');

        $path = ($prefix !== '' ? "/{$prefix}" : '').$request->getPathInfo();

        return "{$scheme}://{$host}{$path}";
    }

    protected function registerPaginationMacro(): void
    {
        if (AnonymousResourceCollection::hasMacro('paginationInformation')) {
            return;
        }

        $normalize = self::normalizePaginationUrl(...);

        AnonymousResourceCollection::macro(
            'paginationInformation',
            function (Request $request, array $paginated, array $default) use ($normalize): array {
                $response = [
                    'links' => array_map($normalize, $default['links'] ?? []),
                ];

                if (isset($paginated['total'])) {
                    $response['meta'] = ['total' => (int) $paginated['total']];
                }

                return $response;
            }
        );
    }

    /**
     * Rewrite ?page=N into the JSON:API ?page[number]=N format.
     */
    protected static function normalizePaginationUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        [$base, $query] = explode('?', $url, 2) + [1 => ''];

        parse_str($query, $params);

        if (isset($params['page']) && is_scalar($params['page'])) {
            $params['page'] = ['number' => (string) $params['page']];
        }

        if ($params === []) {
            return $base;
        }

        return $base.'?'.str_replace(['%5B', '%5D'], ['[', ']'], http_build_query($params));
    }
}
