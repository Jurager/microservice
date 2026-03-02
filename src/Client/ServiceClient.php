<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Jurager\Microservice\Concerns\InteractsWithRedis;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Support\HmacSigner;

class ServiceClient
{
    use InteractsWithRedis;

    protected Client $httpClient;

    public function __construct(
        protected readonly HmacSigner $signer,
    ) {
        $this->httpClient = new Client();
    }

    public function service(string $name): PendingServiceRequest
    {
        return new PendingServiceRequest($this, $name);
    }

    public function send(PendingServiceRequest $request): ServiceResponse
    {
        $service = $request->getService();
        $baseUrl = $this->resolveBaseUrl($service);

        try {
            return $this->executeRequest($request, $baseUrl);
        } catch (GuzzleException $e) {
            throw new ServiceUnavailableException($service, previous: $e);
        }
    }

    /**
     * Resolve the base URL for a service.
     *
     * Resolution order:
     *   1. DNS pattern (SERVICE_DISCOVERY_PATTERN) — e.g. Kubernetes
     *   2. Manifest stored in shared Redis
     */
    protected function resolveBaseUrl(string $service): string
    {
        $pattern = config('microservice.discovery.pattern');

        if ($pattern) {
            return str_replace('{service}', $service, $pattern);
        }

        $raw = $this->redis()->get($this->redisPrefix()."manifest:$service");

        if ($raw) {
            $manifest = json_decode($raw, true);
            $url = $manifest['base_url'] ?? null;

            if ($url) {
                return $url;
            }
        }

        throw new ServiceUnavailableException($service, "Cannot resolve base URL for service [$service]. Make sure the service has registered its manifest.");
    }

    protected function executeRequest(PendingServiceRequest $request, string $baseUrl): ServiceResponse
    {
        $service = $request->getService();
        $method = $request->getMethod();
        $path = $request->getPath();
        $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');

        $timeout = $request->getTimeout() ?? $this->resolveTimeout($service);

        $body = $request->getBody();
        $bodyString = $body !== null
            ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $options = [
            'timeout' => $timeout,
            'http_errors' => false,
            'headers' => $this->buildSignedHeaders($method, $path, $bodyString, $request->getHeaders()),
        ];

        if ($query = $request->getQuery()) {
            $options['query'] = $query;
        }

        if ($bodyString !== null) {
            $options['body'] = $bodyString;
        }

        return new ServiceResponse($this->httpClient->request($method, $url, $options));
    }

    /**
     * Resolve timeout for a service: per-request → manifest → default.
     */
    protected function resolveTimeout(string $service): int
    {
        $raw = $this->redis()->get($this->redisPrefix()."manifest:$service");

        if ($raw) {
            $manifest = json_decode($raw, true);

            if (isset($manifest['timeout'])) {
                return (int) $manifest['timeout'];
            }
        }

        return config('microservice.defaults.timeout', 5);
    }

    protected function buildSignedHeaders(string $method, string $path, ?string $body, array $customHeaders = []): array
    {
        $timestamp = (string) time();

        return [
            ...$customHeaders,
            'Content-Type' => 'application/json',
            'X-Service-Name' => config('microservice.name'),
            'X-Timestamp' => $timestamp,
            'X-Signature' => $this->signer->sign($method, $path, $timestamp, $body ?? ''),
        ];
    }
}
