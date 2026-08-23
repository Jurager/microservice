<?php

declare(strict_types=1);

namespace Jurager\Microservice\Support;

use GuzzleHttp\Client;
use Jurager\Microservice\Registry\ManifestRegistry;
use Throwable;

/** Resolves a peer's public key, published in its manifest, for verifying its signatures. */
class PublicKeyResolver
{
    public function __construct(
        private readonly ManifestRegistry $manifests,
        private readonly ?Client $httpClient = null,
    ) {
    }

    /**
     * Resolve $service's public key, preferring an already-cached manifest and
     * falling back to fetching it live. Pass $force to bypass a stale cache entry —
     * e.g. after a signature failed to verify, in case the peer rotated its key.
     */
    public function resolve(string $service, bool $force = false): ?string
    {
        if (! $force) {
            $cached = $this->fromCache($service);

            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->fetchAndCache($service);
    }

    private function fromCache(string $service): ?string
    {
        $manifest = $this->manifests->get($service);
        $publicKey = $manifest['public_key'] ?? null;

        return is_string($publicKey) && $publicKey !== '' ? $publicKey : null;
    }

    private function fetchAndCache(string $service): ?string
    {
        $pattern = config('microservice.discovery.pattern');

        if (! is_string($pattern) || $pattern === '') {
            return null;
        }

        $baseUrl = str_replace('{service}', $service, $pattern);

        try {
            $response = $this->httpClient()->get(rtrim($baseUrl, '/').'/microservice/manifest', [
                'timeout' => (int) config('microservice.connect_timeout', 5),
                'http_errors' => false,
            ]);

            $manifest = json_decode((string) $response->getBody(), true);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($manifest)) {
            return null;
        }

        $publicKey = $manifest['public_key'] ?? null;

        if (! is_string($publicKey) || $publicKey === '') {
            return null;
        }

        // Cache the whole manifest, not just the key — it's already fetched, and
        // doing so warms route discovery for this peer as a side effect too.
        $manifest['service'] = $service;
        $this->manifests->store($manifest);

        return $publicKey;
    }

    private function httpClient(): Client
    {
        return $this->httpClient ?? new Client();
    }
}
