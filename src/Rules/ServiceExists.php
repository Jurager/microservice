<?php

declare(strict_types=1);

namespace Jurager\Microservice\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Jurager\Microservice\Registry\ManifestRegistry;

class ServiceExists implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail(__('validation.service_exists', ['attribute' => $attribute]));

            return;
        }

        if (! app(ManifestRegistry::class)->has($value)) {
            $fail(__('validation.service_exists', ['attribute' => $attribute]));
        }
    }
}
