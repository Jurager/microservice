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

        // Validate that X-Request-Id is a valid UUID
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
                $this->cacheResponse($cacheKey, $response);
            }

            return $response;
        } finally {
            $this->cache->forget($lockKey);
        }
    }

    protected function cacheResponse(string $key, Response $response): void
    {
        $exclude = ['date', 'set-cookie'];

        $data = [
            'status' => $response->getStatusCode(),
            'headers' => array_diff_key($response->headers->all(), array_flip($exclude)),
            'content' => $response->getContent(),
        ];

        $ttl = config('microservice.idempotency.ttl', 60);
        $this->cache->put($key, $data, $ttl);
    }

    protected function buildCachedResponse(mixed $data, string $requestId, Request $request): Response
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        if (! is_array($data) || ! isset($data['content'], $data['status'])) {
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
