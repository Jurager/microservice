<?php

declare(strict_types=1);

namespace Jurager\Microservice\Concerns;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

trait InteractsWithRedis
{
    protected function redis(): Connection
    {
        return Redis::connection(config('microservice.redis.connection', 'default'));
    }

    protected function redisPrefix(): string
    {
        return (string) config('microservice.redis.prefix', 'microservice:');
    }
}
