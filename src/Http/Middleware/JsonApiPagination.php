<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\PaginationState;
use Symfony\Component\HttpFoundation\Response;

/**
 * Use JSON:API page parameters and pagination links for this request.
 */
class JsonApiPagination
{
    /**
     * Request attribute marking a request this middleware handles.
     */
    public const string ATTRIBUTE = 'microservice.json_api_pagination';

    public function handle(Request $request, Closure $next): Response
    {
        $path = $this->resolveCurrentPath($request);

        $request->attributes->set(self::ATTRIBUTE, true);

        AbstractPaginator::currentPathResolver(static fn (): string => $path);
        AbstractPaginator::currentPageResolver(static function (string $pageName = 'page') use ($request): int {
            $page = $request->input("$pageName.number", $request->input($pageName));

            return filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1 ? (int) $page : 1;
        });

        try {
            return $next($request);
        } finally {
            PaginationState::resolveUsing(app());
        }
    }

    /**
     * Register pagination links for requests handled by this middleware.
     */
    public static function registerLinks(): void
    {
        // A macro is bound to the collection it runs on, so what it needs from here is captured.
        $attribute = self::ATTRIBUTE;
        $normalize = self::normalizePaginationUrl(...);

        AnonymousResourceCollection::macro('paginationInformation', function (Request $request, array $paginated, array $default) use ($attribute, $normalize): array {
            if (! $request->attributes->get($attribute)) {
                return $default;
            }

            $query = $request->query();

            $information = ['links' => array_map(static fn (?string $url): ?string => $normalize($url, $query), $default['links'] ?? [])];

            if (isset($paginated['total'])) {
                $information['meta'] = ['total' => (int) $paginated['total']];
            }

            return $information;
        });
    }

    /**
     * Resolve the pagination URL, trusting gateway URLs only from verified peers.
     */
    protected function resolveCurrentPath(Request $request): string
    {
        $gatewayUrl = $request->header('X-Gateway-Base-Url');

        if (is_string($gatewayUrl) && $gatewayUrl !== '' && TrustPeer::peer($request) !== null) {
            return $gatewayUrl;
        }

        return $request->url();
    }

    /**
     * Rewrite ?page=N into the JSON:API ?page[number]=N format, keeping the rest of the request query.
     */
    protected static function normalizePaginationUrl(?string $url, array $requestQuery = []): ?string
    {
        if ($url === null) {
            return null;
        }

        [$base, $query] = explode('?', $url, 2) + [1 => ''];

        parse_str($query, $params);

        if (isset($params['page']) && is_scalar($params['page'])) {
            $params['page'] = ['number' => (string) $params['page']];
        }

        $params = array_replace_recursive($requestQuery, $params);

        if ($params === []) {
            return $base;
        }

        return $base.'?'.str_replace(['%5B', '%5D'], ['[', ']'], http_build_query($params));
    }
}
