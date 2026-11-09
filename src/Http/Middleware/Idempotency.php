<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Middleware;

use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Jurager\Microservice\Events\IdempotentRequestDetected;
use Jurager\Microservice\Exceptions\DuplicateRequestException;
use Jurager\Microservice\Exceptions\InvalidCacheStateException;
use Jurager\Microservice\Exceptions\InvalidRequestIdException;
use Jurager\Microservice\Tracing\RequestId;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Replay responses for requests with the same ID, method, and path.
 */
class Idempotency
{
    /**
     * Response headers that describe the original delivery rather than the resource.
     */
    private const array VOLATILE_HEADERS = ['date', 'set-cookie'];

    /**
     * @param  int  $ttl  Seconds a response is kept for replay.
     * @param  int  $lockTimeout  Most seconds a request may hold its lock.
     * @param  int  $maxBodySize  Largest body, in bytes, worth keeping; zero removes the limit.
     */
    public function __construct(
        private readonly Cache $cache,
        #[Config('microservice.idempotency.ttl', 86400)] private readonly int $ttl = 86400,
        #[Config('microservice.idempotency.lock_timeout', 10)] private readonly int $lockTimeout = 10,
        #[Config('microservice.idempotency.max_body_size', 1048576)] private readonly int $maxBodySize = 1048576,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! $request->hasHeader('X-Request-Id')) {
            return $next($request);
        }

        $requestId = (string) $request->header('X-Request-Id');

        if (! Str::isUuid($requestId)) {
            throw new InvalidRequestIdException("X-Request-Id must be a valid UUID. Received: $requestId");
        }

        $key = $this->key($request, $requestId);

        if (($cached = $this->cache->get($key)) !== null) {
            return $this->replay($cached, $requestId, $request);
        }

        $lock = $this->lock($key);

        if (! $lock->get()) {
            // Another process is handling the same request — it may have finished meanwhile.
            if (($cached = $this->cache->get($key)) !== null) {
                return $this->replay($cached, $requestId, $request);
            }

            throw new DuplicateRequestException();
        }

        try {
            // The first holder may have stored its response between our read and the lock.
            if (($cached = $this->cache->get($key)) !== null) {
                return $this->replay($cached, $requestId, $request);
            }

            $response = $next($request);

            if ($response->isSuccessful()) {
                $this->store($key, $response);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * Cache key for a request id, scoped to the method and path it was sent to.
     */
    protected function key(Request $request, string $requestId): string
    {
        return 'microservice:idempotency:'.sha1($request->method().' /'.ltrim($request->path(), '/').' '.RequestId::original($requestId));
    }

    protected function lock(string $key): Lock
    {
        if ($this->lockTimeout <= 0) {
            throw new InvalidCacheStateException('Idempotency lock_timeout must be greater than 0.');
        }

        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new InvalidCacheStateException('Idempotency requires a cache store that supports atomic locks.');
        }

        return $store->lock($key.':lock', $this->lockTimeout);
    }

    protected function store(string $key, Response $response): void
    {
        // Reading a streamed body means sending it; it is delivered as-is and never cached.
        if ($response instanceof StreamedResponse) {
            return;
        }

        $content = $response->getContent();

        if (! is_string($content)) {
            return;
        }

        if ($this->maxBodySize > 0 && strlen($content) > $this->maxBodySize) {
            return;
        }

        $this->cache->put($key, [
            'status' => $response->getStatusCode(),
            'headers' => array_diff_key($response->headers->all(), array_flip(self::VOLATILE_HEADERS)),
            'content' => $content,
        ], $this->ttl);
    }

    protected function replay(mixed $data, string $requestId, Request $request): Response
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
            $data['status'],
        );

        return response($data['content'], $data['status'])
            ->withHeaders($data['headers'] ?? [])
            ->header('X-Idempotency-Cache-Hit', 'true');
    }
}
