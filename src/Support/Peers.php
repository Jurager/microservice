<?php

declare(strict_types=1);

namespace Jurager\Microservice\Support;

/**
 * Peers are services reachable outside the network the discovery pattern
 * describes — another gateway in a public contour, for instance. Each one is
 * addressed by an explicit URL, never through the pattern.
 */
final class Peers
{
    /**
     * Base URL by peer name.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $peers = config('microservice.peers', []);

        return is_array($peers) ? $peers : [];
    }

    public static function url(string $name): ?string
    {
        return self::all()[$name] ?? null;
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * Every service whose manifest this gateway pulls: the manifest services
     * of the discovery pattern plus the peers.
     *
     * @return string[]
     */
    public static function withServices(): array
    {
        $services = config('microservice.manifest.services', []);

        return array_values(array_unique([
            ...(is_array($services) ? $services : []),
            ...self::names(),
        ]));
    }
}
