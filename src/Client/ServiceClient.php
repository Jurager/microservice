<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;
use Jurager\Microservice\Events\ServiceRequestFailed;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Registry\HealthRegistry;
use Jurager\Microservice\Support\HmacSigner;

class ServiceClient
{
    protected Client $httpClient;

    public function __construct(
        protected readonly HealthRegistry $registry,
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
        $instances = $this->registry->getHealthyInstances($service);

        if (empty($instances)) {
            $instances = $this->registry->getInstances($service);
        }

        if (empty($instances)) {
            throw new ServiceUnavailableException($service, "No instances configured for service [$service].");
        }

        $lastException = null;

        foreach ($instances as $baseUrl) {
            try {
                if ($response = $this->tryInstance($request, $service, $baseUrl)) {
                    $this->registry->markSuccess($service, $baseUrl);
                    return $response;
                }
            } catch (\Exception $e) {
                $lastException = $e;
            }
        }

        throw new ServiceUnavailableException($service, previous: $lastException);
    }

    protected function tryInstance(PendingServiceRequest $request, string $service, string $baseUrl): ?ServiceResponse
    {
        $retries = $request->getRetries()
            ?? config("microservice.services.$service.retries")
            ?? config('microservice.defaults.retries', 2);

        $retryDelay = config('microservice.defaults.retry_delay', 100);

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            if ($attempt > 0) {
                usleep($retryDelay * 1000);
            }

            try {
                $response = $this->executeRequest($request, $baseUrl);

                if ($response->status() >= 500) {
                    $this->handleFailure($service, $baseUrl, $request, $response->status(), 'Server error');

                    if ($attempt === $retries) {
                        return null;
                    }

                    continue;
                }

                return $response;
            } catch (ConnectException $e) {
                $this->handleFailure($service, $baseUrl, $request, 0, $e->getMessage());

                if ($attempt === $retries) {
                    return null;
                }
            } catch (RequestException $e) {
                $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
                $this->handleFailure($service, $baseUrl, $request, $status, $e->getMessage());

                if ($status >= 400 && $status < 500) {
                    return new ServiceResponse($e->getResponse());
                }

                if ($attempt === $retries) {
                    return null;
                }
            }
        }

        return null;
    }

    protected function executeRequest(PendingServiceRequest $request, string $baseUrl): ServiceResponse
    {
        $method = $request->getMethod();
        $path = $request->getPath();
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        $timeout = $request->getTimeout()
            ?? config("microservice.services.{$request->getService()}.timeout")
            ?? config('microservice.defaults.timeout', 5);

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

    protected function buildSignedHeaders(string $method, string $path, ?string $body, array $customHeaders = []): array
    {
        $timestamp = (string) time();

        return [
            ...$customHeaders,
            'Content-Type' => 'application/json',
            'X-Service-Name' => config('microservice.name'),
            'X-Request-Id' => $customHeaders['X-Request-Id'] ?? Str::uuid()->toString(),
            'X-Timestamp' => $timestamp,
            'X-Signature' => $this->signer->sign($method, $path, $timestamp, $body ?? ''),
        ];
    }

    protected function handleFailure(string $service, string $url, PendingServiceRequest $request, int $statusCode, string $message): void
    {
        $this->registry->markFailure($service, $url);

        ServiceRequestFailed::dispatch($service, $url, $request->getMethod(), $request->getPath(), $statusCode, $message);
    }
}
