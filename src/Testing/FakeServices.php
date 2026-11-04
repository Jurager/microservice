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
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Client\ServiceDiscovery;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Fake service requests using queued HTTP responses.
 */
final class FakeServices
{
    /** @var array<string, ResponseSequence> */
    private array $sequences = [];

    public function __construct(
        private readonly Http $http,
        private readonly ServiceDiscovery $discovery,
    ) {
    }

    /**
     * Queue responses for a service in request order.
     *
     * @param  list<mixed>  $responses
     */
    public function fake(string $service, array $responses): self
    {
        if (! isset($this->sequences[$service])) {
            $this->discovery->usePeer($service, "http://$service.test");
            $this->sequences[$service] = $this->http->sequence();
            $this->http->fake(["$service.test/*" => $this->sequences[$service]]);
        }

        foreach ($responses as $response) {
            $this->sequences[$service]->pushResponse(self::normalize($response));
        }

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
