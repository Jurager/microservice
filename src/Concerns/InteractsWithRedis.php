<?php

declare(strict_types=1);

namespace Jurager\Microservice\Concerns;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;

/**
 * @property-read Factory $redisFactory  Must be declared in the using class constructor.
 */
trait InteractsWithRedis
{
    protected function redis(): Connection
    {
        /** @var Connection */
        return $this->redisFactory->connection(config('microservice.redis.connection', 'default'));
    }

    protected function redisPrefix(): string
    {
        return (string) config('microservice.redis.prefix', 'microservice:');
    }
}
