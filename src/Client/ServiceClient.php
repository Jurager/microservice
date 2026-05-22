<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Jurager\Microservice\Concerns\InteractsWithRedis;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Support\HmacSigner;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Random\RandomException;
use Throwable;

class ServiceClient
{
    use InteractsWithRedis;

    protected Client $httpClient;

    /** @var array<string, array{string, int}> In-memory cache: service → [baseUrl, timeout] */
    protected array $resolvedConfigs = [];

    public function __construct(
        protected readonly HmacSigner $signer,
        ?Client $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? $this->createDefaultClient();
    }

    private function createDefaultClient(): Client
    {
        // CurlMultiHandler supports both sync (request) and async (requestAsync) out of the box.
        $handler = HandlerStack::create(new CurlMultiHandler());

        $maxRetries = (int) config('microservice.retries.max', 0);

        if ($maxRetries > 0) {
            $delay = (int) config('microservice.retries.delay', 100);
            $multiplier = (float) config('microservice.retries.multiplier', 2.0);

            $handler->push(Middleware::retry(
                function (
                    int                $retries,
                    RequestInterface   $request,
                    ?ResponseInterface $response = null,
                    ?Throwable         $exception = null,
                ) use ($maxRetries): bool {
                    if ($retries >= $maxRetries) {
                        return false;
                    }

                    if ($exception instanceof ConnectException) {
                        return true;
                    }

                    return $response !== null && $response->getStatusCode() >= 500;
                },
                static fn (int $retries) => (int) ($delay * ($multiplier ** ($retries - 1))),
            ));
        }

        return new Client(['handler' => $handler]);
    }

    /**
     * @param string $name
     * @return PendingServiceRequest
     */
    public function service(string $name): PendingServiceRequest
    {
        return new PendingServiceRequest($this, $name);
    }

    /**
     * @throws ServiceUnavailableException
     */
    public function send(PendingServiceRequest $request): ServiceResponse
    {
        $service = $request->getService();

        $this->checkCircuitBreaker($service);

        [$baseUrl, $timeout] = $this->resolveServiceConfig($service, $request->getTimeout());

        try {
            $response = $this->executeRequest($request, $baseUrl, $timeout);

            $this->recordCircuitResult($service, true);

            return $response;
        } catch (GuzzleException $e) {
            $this->recordCircuitResult($service, false);

            throw new ServiceUnavailableException($service, previous: $e);
        } catch (ServiceUnavailableException $e) {
            $this->recordCircuitResult($service, false);

            throw $e;
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
     *
     * @throws ServiceUnavailableException
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
                throw new ServiceUnavailableException($requests[$key]->getService(), previous: $result['reason']);
            }
        }

        return $responses;
    }

    /**
     * Resolve the base URL and effective timeout for a service.
     *
     * URL resolution order:
     *   1. DNS pattern (SERVICE_DISCOVERY_PATTERN) — e.g. Kubernetes in-cluster DNS
     *   2. Manifest stored in shared Redis by the service itself
     *
     * Timeout resolution order:
     *   1. Per-request override
     *   2. Timeout advertised in the service manifest
     *   3. Hard default of 30 s
     *
     * @return array{string, int}
     *
     * @throws ServiceUnavailableException
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

    /**
     * @param PendingServiceRequest $request
     * @param string $baseUrl
     * @param int $timeout
     * @return ServiceResponse
     * @throws GuzzleException
     * @throws RandomException
     */
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

    /**
     * @param PendingServiceRequest $request
     * @param string $baseUrl
     * @param int $timeout
     * @return PromiseInterface
     * @throws RandomException
     */
    protected function executeRequestAsync(PendingServiceRequest $request, string $baseUrl, int $timeout): PromiseInterface
    {
        return $this->httpClient->requestAsync(
            $request->getMethod(),
            $this->buildUrl($baseUrl, $request->getPath()),
            $this->buildOptions($request, $timeout),
        );
    }

    /**
     * @param string $baseUrl
     * @param string $path
     * @return string
     */
    protected function buildUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * @param PendingServiceRequest $request
     * @param int $timeout
     * @return array
     * @throws RandomException
     * @throws RandomException
     */
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

    /**
     * @param string $method
     * @param string $path
     * @param string|null $body
     * @param array<string, string> $customHeaders Per-request headers (e.g. X-Request-Id). Service
     *                                               identity headers are added after, so they cannot
     *                                               be overridden by the caller.
     * @param bool $multipart
     * @return array
     * @throws RandomException
     */
    protected function buildSignedHeaders(string $method, string $path, ?string $body, array $customHeaders = [], bool $multipart = false): array
    {
        $timestamp = (string) time();

        $headers = [
            ...$customHeaders,
            'X-Service-Name' => config('microservice.name'),
            'X-Timestamp' => $timestamp,
            // Multipart body is excluded from the signature because its boundary changes per request.
            'X-Signature' => $this->signer->sign($method, $path, $timestamp, $multipart ? '' : ($body ?? '')),
        ];

        if (! $multipart) {
            $headers['Content-Type'] = 'application/json';
        }

        if (config('microservice.tracing.enabled', true)) {
            // Trace headers are prepended so service headers take precedence on name collision.
            $headers = [...$this->buildTraceHeaders(), ...$headers];
        }

        return $headers;
    }

    /**
     * Throws if the circuit breaker is open and the half-open probe window has not elapsed.
     *
     * States: closed (normal) → open (failing) → half-open (probing) → closed.
     *
     * @throws ServiceUnavailableException
     */
    protected function checkCircuitBreaker(string $service): void
    {
        $threshold = (int) config('microservice.circuit_breaker.threshold', 0);

        if ($threshold <= 0) {
            return;
        }

        $key = $this->redisPrefix()."circuit:$service";
        $state = $this->redis()->get($key);

        if ($state === 'open') {
            $openedAt = (int) $this->redis()->get($key.':opened');
            $timeout = (int) config('microservice.circuit_breaker.timeout', 30);

            if (time() - $openedAt < $timeout) {
                throw new ServiceUnavailableException($service, "Circuit breaker is open for service [$service]");
            }

            $this->redis()->set($key, 'half-open');
        }
    }

    /**
     * Records the outcome of a request and transitions the circuit breaker state accordingly.
     *
     * On success: clears the half-open state back to closed.
     * On failure: increments the failure counter; trips the breaker once the threshold is reached.
     */
    protected function recordCircuitResult(string $service, bool $success): void
    {
        $threshold = (int) config('microservice.circuit_breaker.threshold', 0);

        if ($threshold <= 0) {
            return;
        }

        $key = $this->redisPrefix()."circuit:$service";
        $state = $this->redis()->get($key);

        if ($success) {
            if ($state === 'half-open') {
                $this->redis()->del($key, $key.':failures', $key.':opened');
            }

            return;
        }

        if ($state === 'half-open') {
            $timeout = (int) config('microservice.circuit_breaker.timeout', 30);
            $this->redis()->set($key, 'open');
            $this->redis()->set($key.':opened', (string) time());
            $this->redis()->expire($key, $timeout);
            $this->redis()->expire($key.':opened', $timeout);

            return;
        }

        $window = (int) config('microservice.circuit_breaker.window', 60);
        $failures = $this->redis()->incr($key.':failures');

        if ($failures === 1) {
            $this->redis()->expire($key.':failures', $window);
        }

        if ($failures >= $threshold) {
            $timeout = (int) config('microservice.circuit_breaker.timeout', 30);
            $this->redis()->set($key, 'open');
            $this->redis()->set($key.':opened', (string) time());
            $this->redis()->expire($key, $timeout);
            $this->redis()->expire($key.':opened', $timeout);
        }
    }

    /**
     * Builds W3C Trace Context headers for the outgoing request.
     *
     * If the current incoming request carries a traceparent header, a new child
     * span is created within the same trace. Otherwise, a fresh root trace is started.
     *
     * @return array<string, string>
     * @throws RandomException
     */
    private function buildTraceHeaders(): array
    {
        $headers = [];

        $incomingTrace = request()?->header('traceparent');

        if ($incomingTrace && is_string($incomingTrace)) {
            $headers['traceparent'] = $this->newSpanFromTrace($incomingTrace);
        } else {
            $traceId = bin2hex(random_bytes(16));
            $spanId = bin2hex(random_bytes(8));
            $headers['traceparent'] = "00-$traceId-$spanId-00";
        }

        if ($incomingState = request()?->header('tracestate')) {
            $headers['tracestate'] = $incomingState;
        }

        return $headers;
    }

    /**
     * Derives a child span traceparent from an incoming W3C traceparent value.
     * Preserves the trace ID and flags; replaces only the parent span ID.
     * @throws RandomException
     * @throws RandomException
     * @throws RandomException
     */
    private function newSpanFromTrace(string $traceparent): string
    {
        $parts = explode('-', $traceparent);

        if (count($parts) !== 4) {
            $traceId = bin2hex(random_bytes(16));
            $spanId = bin2hex(random_bytes(8));

            return "00-$traceId-$spanId-00";
        }

        $spanId = bin2hex(random_bytes(8));

        return "$parts[0]-$parts[1]-$spanId-$parts[3]";
    }
}
