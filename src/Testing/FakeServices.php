<?php

declare(strict_types=1);

namespace Jurager\Microservice\Testing;

use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Str;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Client\ServiceDiscovery;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Fake service requests using queued or routed HTTP responses.
 */
final class FakeServices
{
    /** @var array<string, ResponseSequence> */
    private array $sequences = [];

    /** @var array<string, list<array<string, mixed>>> Route maps by service, newest first. */
    private array $routes = [];

    public function __construct(
        private readonly Http $http,
        private readonly ServiceDiscovery $discovery,
    ) {
    }

    /**
     * Fake a service: a list is answered in request order, a map of "METHOD /path" routes answers every matching request.
     * Faking also drops the responses memoized from the service.
     *
     * @param  list<mixed>|array<string, mixed>  $responses
     */
    public function fake(string $service, array $responses): self
    {
        if (! isset($this->sequences[$service])) {
            $this->discovery->usePeer($service, "http://$service.test");
            $this->sequences[$service] = $this->http->sequence();
            $this->http->fake(["$service.test/*" => fn (Request $request) => $this->respond($service, $request)]);
        }

        if (array_is_list($responses)) {
            foreach ($responses as $response) {
                $this->sequences[$service]->pushResponse(self::normalize($response));
            }
        } else {
            $this->routes[$service] = [$responses, ...($this->routes[$service] ?? [])];
        }

        app(ServiceClient::class)->forgetMemo($service);

        return $this;
    }

    /**
     * Requests sent to the services so far, optionally narrowed by a filter.
     *
     * @param  (callable(Request, string): bool)|null  $filter  Receives the request and the service name.
     * @return list<Request>
     */
    public function recorded(?callable $filter = null): array
    {
        $requests = [];

        foreach ($this->http->recorded() as [$request]) {
            $service = $request->attributes()[ServiceClient::SERVICE_ATTRIBUTE] ?? null;

            if (is_string($service) && ($filter === null || $filter($request, $service))) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /** @param  callable(Request, string): bool  $callback  Receives the request and the service name. */
    public function assertSent(callable $callback): void
    {
        Assert::assertNotEmpty($this->recorded($callback), 'An expected service request was not sent.');
    }

    /** @param  callable(Request, string): bool  $callback */
    public function assertNotSent(callable $callback): void
    {
        Assert::assertEmpty($this->recorded($callback), 'An unexpected service request was sent.');
    }

    public function assertSentCount(int $count): void
    {
        $sent = count($this->recorded());

        Assert::assertSame($count, $sent, "Expected [$count] service requests, sent [$sent].");
    }

    public function assertNothingSent(): void
    {
        $this->assertSentCount(0);
    }

    /**
     * The first matching route of the newest map, otherwise the next queued response.
     */
    private function respond(string $service, Request $request): mixed
    {
        $path = '/'.ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/');

        foreach ($this->routes[$service] ?? [] as $routes) {
            foreach ($routes as $route => $response) {
                [$method, $pattern] = str_contains($route, ' ') ? explode(' ', $route, 2) : [null, $route];

                if (($method === null || strcasecmp($method, $request->method()) === 0) && Str::is($pattern, $path)) {
                    return self::normalize($response instanceof Closure ? $response($request) : $response);
                }
            }
        }

        return ($this->sequences[$service])($request);
    }

    private static function normalize(mixed $response): mixed
    {
        return match (true) {
            $response instanceof Throwable => Create::rejectionFor($response),
            $response instanceof ResponseInterface => Create::promiseFor($response),
            $response instanceof Response => Create::promiseFor($response->toPsrResponse()),
            is_array($response) || is_string($response) => Http::response($response),
            is_int($response) => Http::response(status: $response),
            $response instanceof PromiseInterface, $response instanceof Closure => $response,
            default => $response,
        };
    }
}
