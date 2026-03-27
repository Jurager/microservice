<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
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
        $this->httpClient = new Client([
            'handler' => HandlerStack::create(new CurlHandler()),
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

        $raw = $this->redis()->get($this->redisPrefix()."manifest:$service");

        if ($raw) {
            $manifest = json_decode($raw, true);
            $url = $manifest['base_url'] ?? null;

            if ($url) {
                $timeout = $requestTimeout ?? (isset($manifest['timeout']) ? (int) $manifest['timeout'] : 30);

                return [$url, $timeout];
            }
        }

        throw new ServiceUnavailableException($service, "Cannot resolve base URL for service [$service]. Make sure the service has registered its manifest.");
    }

    protected function executeRequest(PendingServiceRequest $request, string $baseUrl, int $timeout): ServiceResponse
    {
        $service = $request->getService();
        $method = $request->getMethod();
        $path = $request->getPath();
        $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');

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

        return new ServiceResponse($this->httpClient->request($method, $url, $options));
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
