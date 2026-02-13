<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Jurager\Microservice\Concerns\InteractsWithRedis;
use Jurager\Microservice\Events\IdempotentRequestDetected;
use Jurager\Microservice\Exceptions\DuplicateRequestException;
use Jurager\Microservice\Exceptions\InvalidCacheStateException;
use Jurager\Microservice\Exceptions\InvalidRequestIdException;
use Symfony\Component\HttpFoundation\Response;

class Idempotency
{
    use InteractsWithRedis;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! $request->hasHeader('X-Request-Id')) {
            return $next($request);
        }

        $requestId = $request->header('X-Request-Id');

        // Validate that X-Request-Id is a valid UUID v4
        if (! $this->isValidUuidV4($requestId)) {
            throw new InvalidRequestIdException("X-Request-Id must be a valid UUID v4. Received: $requestId");
        }

        $cacheKey = $this->redisPrefix()."idempotency:$requestId";

        if ($cached = $this->redis()->get($cacheKey)) {
            return $this->buildCachedResponse($cached, $requestId, $request);
        }

        $lockKey = $cacheKey.':lock';
        $lockTimeout = config('microservice.idempotency.lock_timeout', 10);

        if (! $this->redis()->set($lockKey, 'processing', 'EX', $lockTimeout, 'NX')) {
            throw new DuplicateRequestException();
        }

        try {
            $response = $next($request);

            if ($response->isSuccessful()) {
                $this->cacheResponse($cacheKey, $response);
            }

            return $response;
        } finally {
            $this->redis()->del($lockKey);
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
        $this->redis()->setex($key, $ttl, json_encode($data));
    }

    protected function buildCachedResponse(string $cached, string $requestId, Request $request): Response
    {
        $data = json_decode($cached, true);

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

    protected function isValidUuidV4(string $uuid): bool
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

        return preg_match($pattern, $uuid) === 1;
    }
}
