<?php

declare(strict_types=1);

namespace Jurager\Microservice\Manifest;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Store and retrieve service manifests from the shared cache.
 */
class ManifestRepository
{
    private const string PREFIX = 'microservice:manifest:';

    /**
     * List of every service with a stored manifest.
     */
    private const string INDEX = 'microservice:manifests';

    /** @var array<string, string> Compiled URI patterns of resolve(), by pattern — so never stale. */
    private array $patterns = [];

    /** @param  int  $ttl  Seconds a stored manifest lives; zero keeps it until it is replaced. */
    public function __construct(
        private readonly Cache $cache,
        private readonly LoggerInterface $logger,
        #[Config('microservice.manifest.ttl', 0)] private readonly int $ttl = 0,
    ) {
    }

    /**
     * The stored manifest of a service.
     */
    public function get(string $service): ?array
    {
        return self::decode($this->cache->get(self::PREFIX.$service));
    }

    public function has(string $service): bool
    {
        return $this->cache->has(self::PREFIX.$service);
    }

    /**
     * Get stored manifests by service name, or an empty array if the cache is unavailable.
     *
     * @return array<string, array>
     */
    public function all(): array
    {
        try {
            $services = $this->cache->get(self::INDEX, []);

            if (! is_array($services) || $services === []) {
                return [];
            }

            $stored = $this->cache->many(array_map(static fn (string $service): string => self::PREFIX.$service, $services));
        } catch (Throwable $e) {
            $this->logger->warning('Manifests are unreachable, no gateway routes registered.', ['error' => $e->getMessage()]);

            return [];
        }

        $manifests = [];

        foreach ($stored as $raw) {
            $manifest = self::decode($raw);

            if (isset($manifest['service'])) {
                $manifests[$manifest['service']] = $manifest;
            }
        }

        ksort($manifests);

        return $manifests;
    }

    /**
     * Every route of every stored manifest.
     *
     * @return list<array{service: string, methods: list<string>, uri: string, name: string|null}>
     */
    public function routes(): array
    {
        $routes = [];

        foreach ($this->all() as $manifest) {
            foreach ($manifest['routes'] ?? [] as $route) {
                $routes[] = [
                    'service' => $manifest['service'],
                    'methods' => ManifestRoute::methods($route),
                    'uri' => $route['uri'],
                    'name' => $route['name'] ?? null,
                ];
            }
        }

        return $routes;
    }

    /**
     * A hash of what gateway routes are built from: the routes of every stored manifest.
     */
    public function fingerprint(): string
    {
        return md5(json_encode(array_map(
            static fn (array $manifest): array => $manifest['routes'] ?? [],
            $this->all(),
        ), JSON_THROW_ON_ERROR));
    }

    /**
     * The service and route entry answering a method and URI, if any.
     */
    public function resolve(string $method, string $uri): ?array
    {
        $method = strtoupper($method);
        $uri = '/'.ltrim($uri, '/');

        foreach ($this->all() as $manifest) {
            foreach ($manifest['routes'] ?? [] as $route) {
                if (in_array($method, ManifestRoute::methods($route), true) && $this->matches($route['uri'], $uri)) {
                    return ['service' => $manifest['service'], ...$route];
                }
            }
        }

        return null;
    }

    /**
     * Store a manifest, stamped with the time it was synced.
     */
    public function store(array $manifest): void
    {
        $service = $manifest['service'] ?? null;

        if ($service === null) {
            return;
        }

        $this->cache->put(self::PREFIX.$service, [...$manifest, 'synced_at' => now()->toIso8601String()], $this->lifetime());

        $this->updateIndex(static fn (array $services): array => in_array($service, $services, true) ? $services : [...$services, $service]);
    }

    /**
     * Mark a stored manifest as synced again without replacing it.
     */
    public function touch(string $service): void
    {
        $manifest = $this->get($service);

        if ($manifest !== null) {
            $this->cache->put(self::PREFIX.$service, [...$manifest, 'synced_at' => now()->toIso8601String()], $this->lifetime());
        }
    }

    public function remove(string $service): void
    {
        $this->cache->forget(self::PREFIX.$service);

        $this->updateIndex(static fn (array $services): array => array_values(array_diff($services, [$service])));
    }

    /** @param  callable(list<string>): list<string>  $change */
    private function updateIndex(callable $change): void
    {
        try {
            $this->cache->lock(self::INDEX.'_lock', 10)->block(5, function () use ($change): void {
                $this->cache->put(self::INDEX, $change($this->cache->get(self::INDEX, [])));
            });
        } catch (LockTimeoutException) {
            // The manifest itself is stored; the index catches up on the next sync.
        }
    }

    private function lifetime(): ?int
    {
        return $this->ttl > 0 ? $this->ttl : null;
    }

    private function matches(string $pattern, string $uri): bool
    {
        if ($pattern === $uri) {
            return true;
        }

        $this->patterns[$pattern] ??= '#^'.preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($pattern, '#')).'$#';

        return preg_match($this->patterns[$pattern], $uri) === 1;
    }

    /**
     * A manifest as stored — an array, or JSON written by an earlier release.
     */
    private static function decode(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
