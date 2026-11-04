<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Http;
use Jurager\Microservice\Client\CircuitBreaker;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'microservice.peers' => ['oms' => 'http://oms.test'],
            'microservice.circuit_breaker.threshold' => 2,
            'microservice.circuit_breaker.timeout' => 30,
            'microservice.circuit_breaker.window' => 60,
        ]);
    }

    private function client(array $responses): ServiceClient
    {
        Http::fake(['*' => Http::sequence($responses)]);

        return $this->app->make(ServiceClient::class);
    }

    private function refused(): \GuzzleHttp\Promise\PromiseInterface
    {
        return Create::rejectionFor(new ConnectException('refused', new Request('GET', 'http://oms.test')));
    }

    private function breaker(): CircuitBreaker
    {
        return $this->app->make(CircuitBreaker::class);
    }

    /** Send requests that fail at the transport, swallowing the exceptions. */
    private function failTimes(ServiceClient $client, int $times): void
    {
        for ($attempt = 1; $attempt <= $times; $attempt++) {
            try {
                $client->service('oms')->get("/v1/orders/$attempt")->send();
            } catch (ServiceUnavailableException) {
            }
        }
    }

    public function test_is_switched_off_without_a_threshold(): void
    {
        config(['microservice.circuit_breaker.threshold' => 0]);

        $breaker = $this->breaker();
        $breaker->recordFailure('oms');

        $this->assertFalse($breaker->enabled());
        $this->assertNull($breaker->state('oms'));
    }

    public function test_opens_after_the_threshold_and_fails_fast(): void
    {
        $client = $this->client([$this->refused(), $this->refused()]);
        $this->failTimes($client, 2);

        $this->assertSame('open', $this->breaker()->state('oms')['state']);

        try {
            $client->service('oms')->get('/v1/orders/3')->send();
            $this->fail('An open breaker must not let the request through.');
        } catch (ServiceUnavailableException $e) {
            $this->assertStringContainsString('Circuit breaker is open', $e->getMessage());
        }
    }

    public function test_after_the_cooldown_exactly_one_probe_goes_through(): void
    {
        $this->client([$this->refused(), $this->refused()]);
        $this->failTimes($this->app->make(ServiceClient::class), 2);

        $this->travel(31)->seconds();

        $this->assertSame('half-open', $this->breaker()->state('oms')['state']);

        $this->breaker()->ensureClosed('oms');

        $this->expectExceptionMessage('a probe is in progress');

        $this->breaker()->ensureClosed('oms');
    }

    public function test_a_successful_probe_closes_it_again(): void
    {
        $client = $this->client([$this->refused(), $this->refused(), Http::response()]);
        $this->failTimes($client, 2);

        $this->travel(31)->seconds();

        $client->service('oms')->get('/v1/orders/3')->send();

        $this->assertSame('closed', $this->breaker()->state('oms')['state']);
    }

    public function test_a_failed_probe_opens_it_for_another_cooldown(): void
    {
        $client = $this->client([$this->refused(), $this->refused(), $this->refused()]);
        $this->failTimes($client, 2);

        $this->travel(31)->seconds();
        $this->failTimes($client, 1);

        $this->assertSame('open', $this->breaker()->state('oms')['state']);
    }

    public function test_a_success_ends_the_failure_streak(): void
    {
        $client = $this->client([$this->refused(), Http::response(), $this->refused()]);

        $this->failTimes($client, 1);
        $client->service('oms')->get('/v1/orders')->send();
        $this->failTimes($client, 1);

        $this->assertSame('closed', $this->breaker()->state('oms')['state']);
        $this->assertSame(1, $this->breaker()->state('oms')['failures']);
    }

    public function test_parallel_requests_count_towards_the_breaker_and_respect_it(): void
    {
        $client = $this->client([$this->refused(), $this->refused()]);

        try {
            $client->parallel([
                $client->service('oms')->get('/v1/orders/1'),
                $client->service('oms')->get('/v1/orders/2'),
            ]);
        } catch (ServiceUnavailableException) {
        }

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Circuit breaker is open/');

        $client->parallel([$client->service('oms')->get('/v1/orders/3')]);
    }

    public function test_a_request_may_bypass_it(): void
    {
        $client = $this->client([$this->refused(), $this->refused(), Http::response(['ok' => true])]);
        $this->failTimes($client, 2);

        $this->assertTrue($client->service('oms')->get('/v1/health')->withoutCircuitBreaker()->send()->json('ok'));
    }
}
