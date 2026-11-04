<?php

declare(strict_types=1);

namespace Jurager\Microservice\Manifest;

/**
 * Helpers for one route entry of a manifest.
 */
final class ManifestRoute
{
    /**
     * Get uppercase HTTP methods, supporting legacy manifests.
     *
     * @param  array<string, mixed>  $route
     * @return list<string>
     */
    public static function methods(array $route): array
    {
        return array_values(array_map(
            static fn (mixed $method): string => strtoupper((string) $method),
            (array) ($route['methods'] ?? $route['method'] ?? []),
        ));
    }
}
