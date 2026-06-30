<?php

declare(strict_types=1);

namespace Jurager\Microservice\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Jurager\Microservice\Client\ServiceClient;
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
            app(ServiceClient::class)->service($value)
                ->withMethod('HEAD', '/')
                ->timeout(3)
                ->send();
        } catch (ServiceUnavailableException) {
            $fail(__('validation.service_exists', ['attribute' => $attribute]));
        }
    }
}
