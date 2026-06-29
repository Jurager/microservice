<?php

declare(strict_types=1);

namespace Jurager\Microservice\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Redis;

class ServiceExists implements ValidationRule
{
    /**
     * Validate that a service name has a registered manifest in Redis.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('validation.service_exists', ['attribute' => $attribute]));

            return;
        }

        if ((bool) config('microservice.discovery.pattern')) {
            return;
        }

        $prefix = (string) config('microservice.redis.prefix', 'microservice:');
        $connection = (string) config('microservice.redis.connection', 'default');

        $exists = Redis::connection($connection)->exists($prefix.'manifest:'.$value);

        if (! $exists) {
            $fail(__('validation.service_exists', ['attribute' => $attribute]));
        }
    }
}
