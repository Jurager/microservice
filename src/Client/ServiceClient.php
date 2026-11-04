<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use Closure;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Support\Str;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Signing\Signer;
use Jurager\Microservice\Tracing\RequestTiming;
use Jurager\Microservice\Tracing\TraceContext;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Send signed requests through Laravel's HTTP client.
 */
class ServiceClient
{
    use InteractsWithTime;

    /**
     * Request attribute naming the service a request was sent to.
     */
    public const string SERVICE_ATTRIBUTE = 'microservice.service';

    /**
     * @param  string  $name  This service's name, sent with every request.
     * @param  int  $connectTimeout  Most seconds to wait for a TCP connection.
     * @param  bool  $tracing  Propagate W3C trace context and collect Server-Timing durations.
     */
    public function __construct(
        protected readonly Http $http,
        protected readonly Signer $signer,
        protected readonly ServiceDiscovery $discovery,
        protected readonly CircuitBreaker $breaker,
        protected readonly RetryPolicy $retries,
        protected readonly Container $container,
        #[Config('microservice.name', 'app')] protected readonly string $name = 'app',
        #[Config('microservice.connect_timeout', 5)] protected readonly int $connectTimeout = 5,
        #[Config('microservice.tracing.enabled', true)] protected readonly bool $tracing = true,
    ) {}

    /**
     * Start building a request to the given service.
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
        if ($memoized = $this->memo()->get($request)) {
            return $memoized;
        }

        $service = $request->getService();

        $this->guard($request, fn () => $this->breaker->ensureClosed($service));

        $endpoint = $this->discovery->resolve($service);

        try {
            $response = $this->configure($this->http->createPendingRequest(), $request, $endpoint)
                ->send($request->getMethod(), $this->url($endpoint, $request), $this->options($request));
        } catch (ConnectionException|TransferException $e) {
            $this->guard($request, fn () => $this->breaker->recordFailure($service));

            throw new ServiceUnavailableException($service, previous: $e);
        }

        return $this->settled($request, ServiceResponse::from($response));
    }

    /**
     * Send requests concurrently, returning HTTP errors and throwing transport failures.
     *
     * @param  array<string|int, PendingServiceRequest>  $requests
     * @param  int  $concurrency  Most requests in flight at once; zero sends them all at once.
     * @return array<string|int, ServiceResponse>
     *
     * @throws ServiceUnavailableException
     */
    public function parallel(array $requests, int $concurrency = 0): array
    {
        $responses = [];
        $pending = [];

        foreach ($requests as $key => $request) {
            if ($memoized = $this->memo()->get($request)) {
                $responses[$key] = $memoized;

                continue;
            }

            $this->guard($request, fn () => $this->breaker->ensureClosed($request->getService()));

            $pending[$key] = $this->discovery->resolve($request->getService());
        }

        $results = $pending === [] ? [] : $this->http->pool(function (Pool $pool) use ($requests, $pending): void {
            foreach ($pending as $key => $endpoint) {
                $this->configure($pool->as((string) $key), $requests[$key], $endpoint)
                    ->send($requests[$key]->getMethod(), $this->url($endpoint, $requests[$key]), $this->options($requests[$key]));
            }
        }, max(0, $concurrency));

        $failure = null;

        foreach (array_keys($pending) as $key) {
            $request = $requests[$key];
            $result = $results[$key] ?? null;

            if (! $result instanceof Response) {
                $this->guard($request, fn () => $this->breaker->recordFailure($request->getService()));
                $failure ??= new ServiceUnavailableException($request->getService(), previous: $result instanceof Throwable ? $result : null);

                continue;
            }

            $responses[$key] = $this->settled($request, ServiceResponse::from($result));
        }

        if ($failure !== null) {
            throw $failure;
        }

        // Keep the order the requests were given in, whichever were memoized.
        return array_replace(array_intersect_key($requests, $responses), $responses);
    }

    /**
     * Forget memoized responses of one service, or of every service.
     */
    public function forgetMemo(?string $service = null): void
    {
        $this->memo()->forget($service);
    }

    protected function memo(): ResponseMemo
    {
        return $this->container->make(ResponseMemo::class);
    }

    /**
     * Set up Laravel's request for a service request: timeouts, headers, signing, retries and the body.
     */
    protected function configure(PendingRequest $http, PendingServiceRequest $request, ServiceEndpoint $endpoint): PendingRequest
    {
        $http->timeout($request->getTimeout() ?? $endpoint->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withHeaders($this->headers($request))
            ->withAttributes([self::SERVICE_ATTRIBUTE => $request->getService()])
            // Signed before every attempt, so a retry carries a timestamp of its own.
            ->beforeSending(fn (SentRequest $sending): RequestInterface => $this->sign($sending->toPsrRequest(), $request));

        if ($this->retries->enabled()) {
            $http->retry(
                $this->retries->attempts(),
                fn (int $attempt): int => $this->retries->delayFor($attempt),
                fn (Throwable $failure): bool => $this->retries->shouldRetry($failure, $request->isRepeatable()),
                throw: false,
            );
        }

        if (($timing = $this->timing()) !== null) {
            $http->withMiddleware($this->measure($request->getService(), $timing));
        }

        if ($request->getMultipart() !== null) {
            $http->asMultipart();
        } elseif (($body = $request->getBody()) !== null) {
            $http->withBody($body, $request->getContentType());
        }

        return $http;
    }

    /** @return array<string, mixed> */
    protected function options(PendingServiceRequest $request): array
    {
        return $request->getMultipart() !== null ? ['multipart' => $request->getMultipart()] : [];
    }

    protected function url(ServiceEndpoint $endpoint, PendingServiceRequest $request): string
    {
        return rtrim($endpoint->baseUrl, '/').'/'.ltrim($request->target(), '/');
    }

    /** @return array<string, string> */
    protected function headers(PendingServiceRequest $request): array
    {
        $headers = ['Accept' => 'application/json', ...$request->getHeaders()];

        return $this->tracing ? [...TraceContext::headers($this->incoming()), ...$headers] : $headers;
    }

    /**
     * Add the identity headers. Set last, so a request can never claim to come from another service.
     */
    protected function sign(RequestInterface $psr, PendingServiceRequest $request): RequestInterface
    {
        $timestamp = (string) $this->currentTime();
        $body = $request->getMultipart() !== null ? '' : ($request->getBody() ?? '');
        $requestId = $psr->getHeaderLine('X-Request-Id');

        if ($requestId === '') {
            $requestId = (string) Str::uuid();
        }

        $psr = $psr
            ->withHeader('X-Request-Id', $requestId)
            ->withHeader('X-Service-Name', $this->name)
            ->withHeader('X-Timestamp', $timestamp)
            ->withHeader('X-Signature', $this->signer->sign($request->getMethod(), $request->target(), $timestamp, $body, $requestId));

        $certificate = $this->signer->certificate();

        return $certificate !== null ? $psr->withHeader('X-Service-Cert', $certificate) : $psr->withoutHeader('X-Service-Cert');
    }

    /**
     * Record the outcome of an answered request and memoize its response.
     */
    private function settled(PendingServiceRequest $request, ServiceResponse $response): ServiceResponse
    {
        $this->guard($request, fn () => $this->breaker->recordSuccess($request->getService()));
        $this->memo()->remember($request, $response);

        return $response;
    }

    /**
     * Run a circuit breaker step unless the request opted out of it.
     */
    private function guard(PendingServiceRequest $request, Closure $step): void
    {
        if (! $request->bypassesCircuitBreaker()) {
            $step();
        }
    }

    /**
     * Record the request duration and upstream Server-Timing values.
     */
    private function measure(string $service, RequestTiming $timing): Closure
    {
        return static fn (callable $handler): Closure => static function (RequestInterface $psr, array $options) use ($handler, $service, $timing): PromiseInterface {
            $started = hrtime(true);

            return $handler($psr, $options)->then(
                static function (ResponseInterface $response) use ($timing, $service, $started): ResponseInterface {
                    $timing->add('http_'.$service, (hrtime(true) - $started) / 1e6);
                    $timing->upstream($service, $response->getHeaderLine('Server-Timing'));

                    return $response;
                },
                static function (mixed $reason) use ($timing, $service, $started): PromiseInterface {
                    $timing->add('http_'.$service, (hrtime(true) - $started) / 1e6);

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    private function timing(): ?RequestTiming
    {
        $timing = $this->container->make(RequestTiming::class);

        return $timing->enabled() ? $timing : null;
    }

    /**
     * The request being served, when there is one.
     */
    private function incoming(): ?Request
    {
        return $this->container->bound('request') ? $this->container->make('request') : null;
    }
}
