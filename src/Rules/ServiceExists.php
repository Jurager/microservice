<?php

declare(strict_types=1);

namespace Jurager\Microservice\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Redis;

class ServiceExists implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('microservice::validation.service_exists', ['attribute' => $attribute]));

            return;
        }

        $prefix = (string) config('microservice.redis.prefix', 'microservice:');
        $connection = (string) config('microservice.redis.connection', 'default');

        if (! Redis::connection($connection)->exists($prefix.'manifest:'.$value)) {
            $fail(__('microservice::validation.service_exists', ['attribute' => $attribute]));
        }
    }
}
