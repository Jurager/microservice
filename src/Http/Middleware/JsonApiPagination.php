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

        $this->registerPaginationMacro();

        return $next($request);
    }

    protected function registerPaginationMacro(): void
    {
        if (AnonymousResourceCollection::hasMacro('paginationInformation')) {
            return;
        }

        AnonymousResourceCollection::macro(
            'paginationInformation',
            static function (Request $request, array $paginated, array $default): array {
                $links = array_map(

                    static function (?string $url): ?string {

                        if ($url === null) {
                            return null;
                        }

                        [$base] = explode('?', $url, 2);

                        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

                        if (isset($params['page']) && is_scalar($params['page'])) {
                            $params['page'] = ['number' => (string) $params['page']];
                        }

                        if ($params === []) {
                            return $base;
                        }

                        $query = str_replace(['%5B', '%5D'], ['[', ']'], http_build_query($params));

                        return $base.'?'.$query;
                    },
                    $default['links'] ?? []
                );

                $response = ['links' => $links];

                if (isset($paginated['total'])) {
                    $response['meta'] = ['total' => (int) $paginated['total']];
                }

                return $response;
            }
        );
    }
}