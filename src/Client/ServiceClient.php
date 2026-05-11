<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;
use Jurager\Microservice\Concerns\InteractsWithRedis;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Support\HmacSigner;

class ServiceClient
{
    use InteractsWithRedis;

    protected Client $httpClient;

    /** @var array<string, array{string, int}> In-memory cache: service → [baseUrl, timeout] */
    protected array $resolvedConfigs = [];

    public function __construct(
        protected readonly HmacSigner $signer,
    ) {
        $this->httpClient = new Client([
            'handler' => HandlerStack::create(new CurlMultiHandler()),
        ]);
    }

    public function service(string $name): PendingServiceRequest
    {
        return new PendingServiceRequest($this, $name);
    }

    public function send(PendingServiceRequest $request): ServiceResponse
    {
        $service = $request->getService();
        [$baseUrl, $timeout] = $this->resolveServiceConfig($service, $request->getTimeout());

        try {
            return $this->executeRequest($request, $baseUrl, $timeout);
        } catch (GuzzleException $e) {
            throw new ServiceUnavailableException($service, previous: $e);
        }
    }

    /**
     * Send multiple requests concurrently and return responses keyed by the same keys.
     *
     * All requests are dispatched simultaneously. The method blocks until all complete.
     * Failed requests throw ServiceUnavailableException; non-2xx responses are returned
     * as-is so the caller can inspect status codes.
     *
     * @param  array<string|int, PendingServiceRequest>  $requests
     * @return array<string|int, ServiceResponse>
     */
    public function parallel(array $requests): array
    {
        $promises = [];

        foreach ($requests as $key => $pending) {
            $service = $pending->getService();
            [$baseUrl, $timeout] = $this->resolveServiceConfig($service, $pending->getTimeout());

            $promises[$key] = $this->executeRequestAsync($pending, $baseUrl, $timeout);
        }

        $settled = Utils::settle($promises)->wait();

        $responses = [];

        foreach ($settled as $key => $result) {
            if ($result['state'] === 'fulfilled') {
                $responses[$key] = new ServiceResponse($result['value']);
            } else {
                $pending = $requests[$key];
                throw new ServiceUnavailableException($pending->getService(), previous: $result['reason']);
            }
        }

        return $responses;
    }

    /**
     * Resolve base url and timeout.
     *
     * Resolution order for base URL:
     *   1. DNS pattern (SERVICE_DISCOVERY_PATTERN) — e.g. Kubernetes
     *   2. Manifest stored in shared Redis
     *
     * Resolution order for timeout:
     *   1. Per-request timeout
     *   2. Manifest timeout
     *   3. Default (30 s)
     *
     * @return array{string, int}
     */
    protected function resolveServiceConfig(string $service, ?int $requestTimeout): array
    {
        $pattern = config('microservice.discovery.pattern');

        if ($pattern) {
            $baseUrl = str_replace('{service}', $service, $pattern);

            return [$baseUrl, $requestTimeout ?? 30];
        }

        if (isset($this->resolvedConfigs[$service])) {
            [$baseUrl, $defaultTimeout] = $this->resolvedConfigs[$service];

            return [$baseUrl, $requestTimeout ?? $defaultTimeout];
        }

        $raw = $this->redis()->get($this->redisPrefix()."manifest:$service");

        if ($raw) {
            $manifest = json_decode($raw, true);
            $url = $manifest['base_url'] ?? null;

            if ($url) {
                $defaultTimeout = isset($manifest['timeout']) ? (int) $manifest['timeout'] : 30;

                $this->resolvedConfigs[$service] = [$url, $defaultTimeout];

                return [$url, $requestTimeout ?? $defaultTimeout];
            }
        }

        throw new ServiceUnavailableException($service, "Cannot resolve base URL for service [$service]. Make sure the service has registered its manifest.");
    }

    protected function executeRequest(PendingServiceRequest $request, string $baseUrl, int $timeout): ServiceResponse
    {
        return new ServiceResponse(
            $this->httpClient->request(
                $request->getMethod(),
                $this->buildUrl($baseUrl, $request->getPath()),
                $this->buildOptions($request, $timeout),
            )
        );
    }

    protected function executeRequestAsync(PendingServiceRequest $request, string $baseUrl, int $timeout): \GuzzleHttp\Promise\PromiseInterface
    {
        return $this->httpClient->requestAsync(
            $request->getMethod(),
            $this->buildUrl($baseUrl, $request->getPath()),
            $this->buildOptions($request, $timeout),
        );
    }

    protected function buildUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    protected function buildOptions(PendingServiceRequest $request, int $timeout): array
    {
        $method = $request->getMethod();
        $path = $request->getPath();
        $multipart = $request->getMultipart();

        $body = $request->getBody();
        $bodyString = $body !== null
            ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $options = [
            'timeout' => $timeout,
            'http_errors' => false,
            'stream' => true,
            'headers' => $this->buildSignedHeaders($method, $path, $bodyString, $request->getHeaders(), $multipart !== null),
        ];

        if ($query = $request->getQuery()) {
            $options['query'] = $query;
        }

        if ($multipart !== null) {
            $options['multipart'] = $multipart;
        } elseif ($bodyString !== null) {
            $options['body'] = $bodyString;
        }

        return $options;
    }

    protected function buildSignedHeaders(string $method, string $path, ?string $body, array $customHeaders = [], bool $multipart = false): array
    {
        $timestamp = (string) time();

        $headers = [
            ...$customHeaders,
            'X-Service-Name' => config('microservice.name'),
            'X-Timestamp' => $timestamp,
            'X-Signature' => $this->signer->sign($method, $path, $timestamp, $multipart ? '' : ($body ?? '')),
        ];

        if (! $multipart) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }
}
