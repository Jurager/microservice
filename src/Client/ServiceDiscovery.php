<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use Illuminate\Container\Attributes\Config;
use Illuminate\Support\InteractsWithTime;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Manifest\ManifestRepository;

/**
 * Resolve service URLs from peers, the discovery pattern, or cached manifests.
 */
class ServiceDiscovery
{
    use InteractsWithTime;

    /** @var array<string, array{ServiceEndpoint, int}> Manifest-resolved endpoints and when they were read. */
    private array $resolved = [];

    /**
     * @param  array<string, string>  $peers  Base URL by peer name.
     * @param  list<string>  $services  Services behind the discovery pattern whose manifests are synced.
     * @param  string|null  $pattern  URL template with a `{service}` placeholder.
     * @param  int  $cacheTtl  Seconds a base URL read from a manifest is reused.
     * @param  int  $timeout  Request timeout when a manifest advertises none.
     */
    public function __construct(
        private readonly ManifestRepository $manifests,
        #[Config('microservice.peers', [])] private array $peers = [],
        #[Config('microservice.manifest.services', [])] private readonly array $services = [],
        #[Config('microservice.discovery.pattern')] private readonly ?string $pattern = null,
        #[Config('microservice.discovery.cache_ttl', 60)] private readonly int $cacheTtl = 60,
        #[Config('microservice.manifest.timeout', 30)] private readonly int $timeout = 30,
    ) {
    }

    /**
     * @throws ServiceUnavailableException
     */
    public function resolve(string $service): ServiceEndpoint
    {
        if (isset($this->peers[$service])) {
            return new ServiceEndpoint($this->peers[$service], $this->timeout);
        }

        if ($this->pattern !== null && $this->pattern !== '') {
            return new ServiceEndpoint(str_replace('{service}', $service, $this->pattern), $this->timeout);
        }

        return $this->fromManifest($service)
            ?? throw new ServiceUnavailableException($service, "Cannot resolve base URL for service [$service]. Make sure the service has registered its manifest.");
    }

    /**
     * Every service whose manifest is synced: those behind the pattern, then the peers.
     *
     * @return list<string>
     */
    public function services(): array
    {
        return array_values(array_unique([...$this->services, ...array_keys($this->peers)]));
    }

    /** @return array<string, string> Base URL by peer name. */
    public function peers(): array
    {
        return $this->peers;
    }

    /**
     * Address a service by its own URL from now on — e.g. a faked service in tests.
     */
    public function usePeer(string $service, string $url): void
    {
        $this->peers[$service] = $url;
        unset($this->resolved[$service]);
    }

    /**
     * Forget endpoints read from manifests, so the next request reads them again.
     */
    public function forget(?string $service = null): void
    {
        if ($service === null) {
            $this->resolved = [];

            return;
        }

        unset($this->resolved[$service]);
    }

    private function fromManifest(string $service): ?ServiceEndpoint
    {
        [$endpoint, $resolvedAt] = $this->resolved[$service] ?? [null, 0];

        if ($endpoint !== null && $this->currentTime() - $resolvedAt < $this->cacheTtl) {
            return $endpoint;
        }

        $manifest = $this->manifests->get($service);
        $url = $manifest['base_url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        $endpoint = new ServiceEndpoint($url, isset($manifest['timeout']) ? (int) $manifest['timeout'] : $this->timeout);

        $this->resolved[$service] = [$endpoint, $this->currentTime()];

        return $endpoint;
    }
}
