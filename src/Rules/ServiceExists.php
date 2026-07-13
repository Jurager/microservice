<?php

declare(strict_types=1);

namespace Jurager\Microservice\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;

class ServiceExists implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('validation.service_exists', ['attribute' => $attribute]));

            return;
        }

        try {
            $endpoint = config('microservice.health.liveness_endpoint', '/microservice/health/live');

            app(ServiceClient::class)->service($value)
                ->get($endpoint)
                ->timeout(3)
                ->withoutCircuitBreaker()
                ->send();
        } catch (ServiceUnavailableException|ServiceRequestException) {
            $fail(__('validation.service_exists', ['attribute' => $attribute]));
        }
    }
}
