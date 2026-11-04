<?php

declare(strict_types=1);

namespace Jurager\Microservice\Health\Checks;

use Illuminate\Contracts\Cache\Repository as Cache;
use Jurager\Microservice\Health\Contracts\DependencyCheck;
use Jurager\Microservice\Health\DependencyStatus;
use Throwable;

/**
 * The shared cache: manifests, idempotency keys and breaker state live there, so it is critical.
 */
class CacheCheck implements DependencyCheck
{
    public function __construct(private readonly Cache $cache)
    {
    }

    public function name(): string
    {
        return 'cache';
    }

    public function critical(): bool
    {
        return true;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function check(): DependencyStatus
    {
        $started = microtime(true);

        try {
            $this->cache->has('microservice:ping');

            return DependencyStatus::up(round((microtime(true) - $started) * 1000, 2));
        } catch (Throwable $e) {
            return DependencyStatus::down($e);
        }
    }
}
