<?php

declare(strict_types=1);

namespace Jurager\Microservice\Rules;

use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Contracts\Validation\ValidationRule;

class ServiceExists implements ValidationRule
{
    /**
     * Validate that a service name has a registered manifest in Redis.
     */
    public function __construct(
        private readonly RedisFactory $redis,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('microservice::validation.service_exists', ['attribute' => $attribute]));

            return;
        }

        $prefix = (string) config('microservice.redis.prefix', 'microservice:');
        $connection = (string) config('microservice.redis.connection', 'default');

        $exists = $this->redis->connection($connection)->exists($prefix.'manifest:'.$value);

        if (! $exists) {
            $fail(__('microservice::validation.service_exists', ['attribute' => $attribute]));
        }
    }
}
