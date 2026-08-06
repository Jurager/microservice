<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Jurager\Microservice\Events\IdempotentRequestDetected;
use Jurager\Microservice\Exceptions\DuplicateRequestException;
use Jurager\Microservice\Exceptions\InvalidCacheStateException;
use Jurager\Microservice\Exceptions\InvalidRequestIdException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Idempotency
{
    public function __construct(private readonly Cache $cache)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! $request->hasHeader('X-Request-Id')) {
            return $next($request);
        }

        $requestId = $request->header('X-Request-Id');

        if (! Str::isUuid($requestId)) {
            throw new InvalidRequestIdException("X-Request-Id must be a valid UUID. Received: $requestId");
        }

        $cacheKey = "microservice:idempotency:$requestId";

        if ($cached = $this->cache->get($cacheKey)) {
            return $this->buildCachedResponse($cached, $requestId, $request);
        }

        $lockKey = $cacheKey.':lock';
        $lockTimeout = (int) config('microservice.idempotency.lock_timeout', 10);

        if ($lockTimeout <= 0) {
            throw new InvalidCacheStateException('Idempotency lock_timeout must be greater than 0.');
        }

        if (! $this->cache->add($lockKey, 'processing', $lockTimeout)) {

            // Another process holds the lock — check if it already cached the response.
            if ($cached = $this->cache->get($cacheKey)) {
                return $this->buildCachedResponse($cached, $requestId, $request);
            }

            throw new DuplicateRequestException();
        }

        try {
            $response = $next($request);

            if ($response->isSuccessful()) {
                $response = $this->cacheResponse($cacheKey, $response);
            }

            return $response;
        } finally {
            $this->cache->forget($lockKey);
        }
    }

    /**
     * Store the response for replay, returning the response to send to the client.
     */
    protected function cacheResponse(string $key, Response $response): Response
    {
        [$response, $content] = $this->materialize($response);

        if ($content === null) {
            return $response;
        }

        // A body too large to cache is still a valid response — send it,
        // just don't let it flood the cache. A replay will re-run the request.
        $limit = (int) config('microservice.idempotency.max_body_size', 1048576);

        if ($limit > 0 && strlen($content) > $limit) {
            return $response;
        }

        $exclude = ['date', 'set-cookie'];

        $data = [
            'status' => $response->getStatusCode(),
            'headers' => array_diff_key($response->headers->all(), array_flip($exclude)),
            'content' => $content,
        ];

        $ttl = config('microservice.idempotency.ttl', 60);
        $this->cache->put($key, $data, $ttl);

        return $response;
    }

    /**
     * Resolve a response into [response to send, body to cache].
     *
     * @return array{Response, string|null}
     */
    private function materialize(Response $response): array
    {
        if (! $response instanceof StreamedResponse) {
            $content = $response->getContent();

            return [$response, is_string($content) ? $content : null];
        }

        $content = '';

        // A callback buffer keeps the capture intact when the streaming callback
        // flushes mid-way, which a plain ob_get_clean() would lose.
        ob_start(static function (string $chunk) use (&$content): string {
            $content .= $chunk;

            return '';
        });

        try {
            $response->sendContent();
        } finally {
            ob_end_clean();
        }

        return [new Response($content, $response->getStatusCode(), $response->headers->all()), $content];
    }

    protected function buildCachedResponse(mixed $data, string $requestId, Request $request): Response
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        
        if (! is_array($data) || ! isset($data['status']) || ! is_string($data['content'] ?? null)) {
            throw new InvalidCacheStateException();
        }

        IdempotentRequestDetected::dispatch(
            $requestId,
            $request->method(),
            $request->path(),
            $data['status']
        );

        return response($data['content'], $data['status'])
            ->withHeaders($data['headers'] ?? [])
            ->header('X-Idempotency-Cache-Hit', 'true');
    }
}
