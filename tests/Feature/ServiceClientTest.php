<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Signing\Signer;
use Jurager\Microservice\Tests\TestCase;
use Jurager\Microservice\Tracing\RequestTiming;
use Mockery;
use Throwable;

class ServiceClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['microservice.discovery.pattern' => 'http://{service}:8000']);
    }

    /** @param  list<mixed>  $responses  Http::response() promises, or Throwables for transport failures. */
    private function client(array $responses = [], ?Cache $cache = null): ServiceClient
    {
        Http::fake(['*' => Http::sequence(array_map(
            static fn (mixed $response) => $response instanceof Throwable ? Create::rejectionFor($response) : $response,
            $responses,
        ))]);

        if ($cache !== null) {
            $this->app->instance(Cache::class, $cache);
        }

        return $this->app->make(ServiceClient::class);
    }

    private function sent(int $index = 0): Request
    {
        return Http::recorded()[$index][0];
    }

    private function refused(string $url = 'http://oms:8000/api/orders'): ConnectException
    {
        return new ConnectException('Connection refused', new PsrRequest('GET', $url));
    }

    private function cacheWithManifest(string $service, string $baseUrl, ?int $timeout = null): Cache
    {
        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('get')
            ->with("microservice:manifest:$service")
            ->andReturn(['service' => $service, 'base_url' => $baseUrl, 'timeout' => $timeout, 'routes' => []]);

        return $cache;
    }

    public function test_successful_request_returns_service_response(): void
    {
        $response = $this->client([Http::response(['data' => 'ok'])])->service('oms')->get('/api/orders')->send();

        $this->assertTrue($response->successful());
        $this->assertSame(200, $response->status());
        $this->assertSame('ok', $response->json('data'));
    }

    public function test_requests_are_visible_to_laravel_fakes_and_tagged_with_the_service(): void
    {
        $this->client([Http::response()])->service('oms')->get('/api/orders')->send();

        Http::assertSent(fn (Request $request) => $request->url() === 'http://oms:8000/api/orders'
            && $request->attributes()[ServiceClient::SERVICE_ATTRIBUTE] === 'oms');
    }

    public function test_peer_url_takes_precedence_over_discovery_pattern(): void
    {
        config(['microservice.peers' => ['api' => 'https://api.example.com']]);

        $client = $this->client([Http::response(), Http::response()]);

        $client->service('api')->get('/v1/secure/attributes')->send();
        $client->service('oms')->get('/v1/orders')->send();

        $this->assertSame('https://api.example.com/v1/secure/attributes', $this->sent(0)->url());
        $this->assertSame('http://oms:8000/v1/orders', $this->sent(1)->url());
    }

    public function test_peer_is_never_resolved_from_its_own_manifest(): void
    {
        config(['microservice.peers' => ['api' => 'https://api.example.com']]);

        $this->client([Http::response()], $this->cacheWithManifest('api', 'https://elsewhere.internal'))
            ->service('api')->get('/v1/secure/attributes')->send();

        $this->assertStringStartsWith('https://api.example.com/', $this->sent()->url());
    }

    public function test_request_carries_identity_and_signature_headers(): void
    {
        $this->client([Http::response()])->service('oms')->get('/api/orders')->send();

        $request = $this->sent();
        $this->assertTrue($request->hasHeader('X-Signature'));
        $this->assertTrue($request->hasHeader('X-Timestamp'));
        $this->assertTrue($request->hasHeader('X-Service-Cert'));
        $this->assertSame('test-service', $request->header('X-Service-Name')[0]);
        $this->assertSame('application/json', $request->header('Accept')[0]);
    }

    public function test_a_caller_cannot_claim_to_be_another_service(): void
    {
        $this->client([Http::response()])->service('oms')->get('/api/orders')
            ->withHeaders(['X-Service-Name' => 'admin', 'X-Signature' => 'forged'])
            ->send();

        $this->assertSame(['test-service'], $this->sent()->header('X-Service-Name'));
        $this->assertNotSame(['forged'], $this->sent()->header('X-Signature'));
    }

    public function test_forwards_custom_headers(): void
    {
        $requestId = '550e8400-e29b-41d4-a716-446655440000';

        $this->client([Http::response()])->service('oms')->get('/api/orders')->withHeaders(['X-Request-Id' => $requestId])->send();

        $this->assertSame([$requestId], $this->sent()->header('X-Request-Id'));
    }

    public function test_a_write_carries_a_request_id_of_its_own(): void
    {
        $this->client([Http::response(), Http::response()])->service('oms')->post('/api/orders', ['a' => 1])->send();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->sent()->header('X-Request-Id')[0]);
    }

    public function test_safe_request_signatures_survive_a_proxy_adding_request_ids(): void
    {
        $client = $this->client(array_fill(0, 3, Http::response()));
        $signer = $this->app->make(Signer::class);

        foreach (['GET', 'HEAD', 'OPTIONS'] as $index => $method) {
            $client->service('oms')->withMethod($method, '/microservice/manifest')->send();

            $sent = $this->sent($index);
            $received = \Illuminate\Http\Request::create($sent->url(), $method, content: $sent->body());
            $requestId = $sent->header('X-Request-Id')[0] ?? (string) Str::uuid();
            $received->headers->set('X-Request-Id', $requestId);

            $this->assertTrue($signer->verify(
                $received,
                $sent->header('X-Signature')[0],
                $sent->header('X-Timestamp')[0],
                $sent->header('X-Service-Cert')[0],
                'test-service',
            ), "$method must sign a request ID before a proxy can add one.");
        }
    }

    public function test_4xx_response_throws_with_the_upstream_status(): void
    {
        try {
            $this->client([Http::response(['errors' => [['detail' => 'Name is required']]], 422)])->service('oms')->get('/api/orders/999')->send();
            $this->fail('Expected ServiceRequestException.');
        } catch (ServiceRequestException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame([['detail' => 'Name is required']], $e->errors);
            $this->assertTrue($e->rendersUpstreamErrors());
            $this->assertSame('Service [oms] request failed with status 422.', $e->getMessage());
        }
    }

    public function test_a_failing_service_reaches_the_client_as_bad_gateway(): void
    {
        $statuses = [500, 503, 401, 407];
        $client = $this->client(array_map(fn (int $status) => Http::response(['errors' => [['detail' => 'SQLSTATE[42S02]']]], $status), $statuses));

        foreach ($statuses as $status) {
            try {
                $client->service('oms')->get("/api/orders/$status")->send();
                $this->fail('Expected ServiceRequestException.');
            } catch (ServiceRequestException $e) {
                $this->assertSame($status, $e->status);
                $this->assertSame(502, $e->getStatusCode());
                $this->assertFalse($e->rendersUpstreamErrors(), 'Upstream details of a failing service are not rendered.');
            }
        }
    }

    public function test_connect_exception_throws_service_unavailable(): void
    {
        $this->expectException(ServiceUnavailableException::class);

        $this->client([$this->refused()])->service('oms')->get('/api/orders')->send();
    }

    public function test_post_sends_json_body(): void
    {
        $this->client([Http::response(['id' => 1], 201)])->service('oms')->post('/api/orders', ['product_id' => 1, 'name' => 'Стол/desk'])->send();

        $this->assertSame('{"product_id":1,"name":"Стол/desk"}', $this->sent()->body());
        $this->assertSame(['application/json'], $this->sent()->header('Content-Type'));
    }

    public function test_a_raw_body_is_sent_as_given(): void
    {
        $this->client([Http::response()])->service('oms')->post('/api/orders')->withBody('{"data":{}}', 'application/vnd.api+json')->send();

        $this->assertSame('{"data":{}}', $this->sent()->body());
        $this->assertSame(['application/vnd.api+json'], $this->sent()->header('Content-Type'));
    }

    public function test_query_parameters_are_sent(): void
    {
        $this->client([Http::response()])->service('oms')->get('/api/orders')->withQueryParameters(['page' => 2, 'limit' => 10, 'skip' => null])->send();

        $this->assertSame('http://oms:8000/api/orders?page=2&limit=10', $this->sent()->url());
    }

    public function test_resolves_url_from_cache_manifest(): void
    {
        config(['microservice.discovery.pattern' => null]);

        $this->client([Http::response()], $this->cacheWithManifest('oms', 'http://oms-from-cache:9000'))->service('oms')->get('/api/orders')->send();

        $this->assertStringStartsWith('http://oms-from-cache:9000/', $this->sent()->url());
    }

    public function test_throws_when_url_cannot_be_resolved(): void
    {
        config(['microservice.discovery.pattern' => null]);

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('get')->andReturn(null);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessageMatches('/Cannot resolve base URL/');

        $this->client([], $cache)->service('unknown')->get('/api/test')->send();
    }

    public function test_timeouts_come_from_the_manifest_the_request_or_the_default(): void
    {
        config(['microservice.discovery.pattern' => null]);

        $timeouts = [];
        Http::fake(function (Request $request, array $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'];

            return Http::response();
        });

        $this->app->instance(Cache::class, $this->cacheWithManifest('oms', 'http://oms:8000', 15));
        $client = $this->app->make(ServiceClient::class);

        $client->service('oms')->get('/api/orders/1')->send();
        $client->service('oms')->get('/api/orders/2')->timeout(3)->send();

        $this->assertSame([15, 3], $timeouts);
    }

    public function test_multipart_request_is_signed_without_its_body(): void
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, '{"products":[]}');
        rewind($stream);

        $this->client([Http::response(['id' => 1], 201)])->service('pim')->post('/api/import')
            ->withMultipart([
                ['name' => 'import_type', 'contents' => 'products'],
                ['name' => 'import_file', 'contents' => $stream, 'filename' => 'products.json'],
            ])
            ->send();

        $request = $this->sent();
        $this->assertStringContainsString('multipart/form-data', $request->header('Content-Type')[0]);
        $this->assertStringContainsString('{"products":[]}', $request->body());
        $this->assertTrue($request->hasHeader('X-Signature'));
    }

    public function test_get_requests_are_memoized_within_request(): void
    {
        $client = $this->client([Http::response(['data' => 'first'])]);

        $first = $client->service('oms')->get('/api/orders/1')->send();
        $second = $client->service('oms')->get('/api/orders/1')->send();

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_memoization_key_includes_query_and_headers(): void
    {
        $client = $this->client(array_fill(0, 3, Http::response()));

        $client->service('oms')->get('/api/orders')->withQueryParameters(['page' => 1])->send();
        $client->service('oms')->get('/api/orders')->withQueryParameters(['page' => 2])->send();
        $client->service('oms')->get('/api/orders')->withQueryParameters(['page' => 2])->withHeaders(['Accept-Language' => 'de'])->send();

        Http::assertSentCount(3);
    }

    public function test_a_failed_response_is_never_memoized(): void
    {
        $client = $this->client([Http::response(status: 503), Http::response(['data' => 'recovered'])]);

        $client->service('oms')->get('/api/orders/1')->withoutThrowing()->send();

        $this->assertSame('recovered', $client->service('oms')->get('/api/orders/1')->send()->json('data'));
    }

    public function test_post_requests_are_not_memoized(): void
    {
        $client = $this->client([Http::response(), Http::response()]);

        $client->service('pim')->post('/api/attributes/search', ['filter' => ['id' => 1]])->send();
        $client->service('pim')->post('/api/attributes/search', ['filter' => ['id' => 1]])->send();

        Http::assertSentCount(2);
    }

    public function test_forget_memo_clears_one_service_or_all(): void
    {
        $client = $this->client(array_fill(0, 4, Http::response()));

        $client->service('oms')->get('/api/orders/1')->send();
        $client->forgetMemo('oms');
        $client->service('oms')->get('/api/orders/1')->send();
        $client->forgetMemo();
        $client->service('oms')->get('/api/orders/1')->send();

        Http::assertSentCount(3);
    }

    public function test_successful_write_invalidates_memoized_reads_for_same_service(): void
    {
        $client = $this->client([
            Http::response(['meta' => ['total' => 0]]),
            Http::response(['data' => ['id' => 37]], 201),
            Http::response(['meta' => ['total' => 1]]),
        ]);

        $before = $client->service('oms')->get('/v1/baskets')->send();
        $client->service('oms')->post('/v1/baskets/store', ['fuser_id' => 1])->send();
        $after = $client->service('oms')->get('/v1/baskets')->send();

        Http::assertSentCount(3);
        $this->assertSame(0, $before->json('meta.total'));
        $this->assertSame(1, $after->json('meta.total'));
    }

    public function test_failed_write_does_not_invalidate_memoized_reads(): void
    {
        $client = $this->client([Http::response(['meta' => ['total' => 0]]), Http::response(['errors' => []], 422)]);

        $before = $client->service('oms')->get('/v1/baskets')->send();
        $client->service('oms')->post('/v1/baskets/store', ['fuser_id' => 1])->withoutThrowing()->send();
        $after = $client->service('oms')->get('/v1/baskets')->send();

        Http::assertSentCount(2);
        $this->assertSame($before, $after);
    }

    public function test_write_to_other_service_does_not_invalidate_memo(): void
    {
        $client = $this->client([Http::response(['data' => 'a']), Http::response(['data' => 'created'], 201)]);

        $client->service('oms')->get('/api/orders/1')->send();
        $client->service('pim')->post('/api/attributes', ['name' => 'x'])->send();
        $client->service('oms')->get('/api/orders/1')->send();

        Http::assertSentCount(2);
    }

    public function test_memoized_responses_do_not_outlive_the_unit_of_work(): void
    {
        $client = $this->client([Http::response(['data' => 'a']), Http::response(['data' => 'b'])]);

        $client->service('oms')->get('/api/orders/1')->send();

        // What the queue worker and Octane do between jobs and requests.
        $this->app->forgetScopedInstances();

        $this->assertSame('b', $client->service('oms')->get('/api/orders/1')->send()->json('data'));
    }

    public function test_parallel_sends_concurrently_and_keeps_keys_and_order(): void
    {
        $client = $this->client([Http::response(['data' => 'a']), Http::response(['data' => 'b'])]);

        $client->service('oms')->get('/api/orders/1')->send();

        $responses = $client->parallel([
            'first' => $client->service('oms')->get('/api/orders/1'),
            'second' => $client->service('oms')->get('/api/orders/2'),
        ]);

        Http::assertSentCount(2);
        $this->assertSame(['first', 'second'], array_keys($responses));
        $this->assertSame('a', $responses['first']->json('data'));
        $this->assertSame('b', $responses['second']->json('data'));
    }

    public function test_parallel_write_invalidates_memoized_reads_for_same_service(): void
    {
        $client = $this->client([
            Http::response(['meta' => ['total' => 0]]),
            Http::response(['data' => ['id' => 37]], 201),
            Http::response(['meta' => ['total' => 1]]),
        ]);

        $client->service('oms')->get('/v1/baskets')->send();
        $client->parallel(['write' => $client->service('oms')->post('/v1/baskets/store', ['fuser_id' => 1])]);

        $this->assertSame(1, $client->service('oms')->get('/v1/baskets')->send()->json('meta.total'));
    }

    public function test_parallel_throws_when_a_request_cannot_be_delivered(): void
    {
        $client = $this->client([Http::response(), $this->refused()]);

        $this->expectException(ServiceUnavailableException::class);

        $client->parallel([$client->service('oms')->get('/a'), $client->service('oms')->get('/b')]);
    }

    public function test_a_safe_request_is_retried_on_a_server_error(): void
    {
        config(['microservice.retries.max' => 2, 'microservice.retries.delay' => 0]);

        $response = $this->client([Http::response(status: 503), Http::response(['data' => 'ok'])])->service('oms')->get('/api/orders')->send();

        $this->assertSame('ok', $response->json('data'));
        Http::assertSentCount(2);
    }

    public function test_a_write_is_not_retried_on_a_server_error(): void
    {
        config(['microservice.retries.max' => 2, 'microservice.retries.delay' => 0]);

        $response = $this->client([Http::response(status: 500), Http::response(status: 201)])
            ->service('oms')->post('/api/orders', ['a' => 1])->withoutThrowing()->send();

        $this->assertSame(500, $response->status());
        Http::assertSentCount(1);
    }

    public function test_a_write_marked_idempotent_is_retried_and_re_signed(): void
    {
        config(['microservice.retries.max' => 2, 'microservice.retries.delay' => 0]);

        $response = $this->client([Http::response(status: 503), Http::response(status: 201)])
            ->service('oms')->post('/api/orders', ['a' => 1])->idempotent()->send();

        $this->assertSame(201, $response->status());
        Http::assertSentCount(2);
        $this->assertSame($this->sent(0)->header('X-Request-Id'), $this->sent(1)->header('X-Request-Id'));
        $this->assertTrue($this->sent(1)->hasHeader('X-Signature'));
    }

    public function test_a_write_whose_connection_never_opened_is_retried(): void
    {
        config(['microservice.retries.max' => 2, 'microservice.retries.delay' => 0]);

        $never = new ConnectException('Could not resolve host', new PsrRequest('POST', 'http://oms:8000'), handlerContext: ['errno' => CURLE_COULDNT_RESOLVE_HOST]);
        $timedOut = new ConnectException('Operation timed out', new PsrRequest('POST', 'http://oms:8000'), handlerContext: ['errno' => CURLE_OPERATION_TIMEOUTED]);

        $client = $this->client([$never, Http::response(status: 201), $timedOut, Http::response(status: 201)]);

        $this->assertSame(201, $client->service('oms')->post('/api/orders', ['a' => 1])->send()->status());

        $this->expectException(ServiceUnavailableException::class);

        // A timeout may have reached the peer, so the write is not repeated.
        $client->service('oms')->post('/api/orders', ['a' => 2])->send();
    }

    public function test_a_client_error_is_never_retried(): void
    {
        config(['microservice.retries.max' => 2, 'microservice.retries.delay' => 0]);

        $this->client([Http::response(status: 404), Http::response()])->service('oms')->get('/api/orders/1')->withoutThrowing()->send();

        Http::assertSentCount(1);
    }

    public function test_the_signature_covers_query_and_request_id_as_the_peer_receives_them(): void
    {
        config(['microservice.peers' => ['oms' => 'http://oms.test']]);

        $this->client([Http::response()])->service('oms')->post('/api/orders', ['a' => 1])
            ->withQueryParameters(['filter' => ['status' => 'new paid'], 'page' => ['number' => 2]])
            ->send();

        $sent = $this->sent();
        $received = \Illuminate\Http\Request::create($sent->url(), 'POST', content: $sent->body());
        $received->headers->set('X-Request-Id', $sent->header('X-Request-Id')[0]);
        $signer = $this->app->make(Signer::class);
        $verify = fn () => $signer->verify($received, $sent->header('X-Signature')[0], $sent->header('X-Timestamp')[0], $sent->header('X-Service-Cert')[0], 'test-service');

        $this->assertTrue($verify());

        // Replayed under another request id, the signature no longer holds.
        $received->headers->set('X-Request-Id', '00000000-0000-0000-0000-000000000000');
        $this->assertFalse($verify());
    }

    public function test_upstream_render_timings_are_forwarded_to_the_incoming_request(): void
    {
        $incoming = \Illuminate\Http\Request::create('/products');
        $this->app->instance('request', $incoming);

        $this->client([Http::response('{}', 200, ['Server-Timing' => 'jsonapi_load;dur=12.5, jsonapi_render;dur=3, private_metric;dur=99'])])
            ->service('pim')->get('/products')->send();

        $header = $this->app->make(RequestTiming::class)->header();
        $this->assertStringContainsString('http_pim;dur=', $header);
        $this->assertStringContainsString('pim_jsonapi_load;dur=12.500', $header);
        $this->assertStringContainsString('pim_jsonapi_render;dur=3.000', $header);
        $this->assertStringNotContainsString('private_metric', $header);
    }

    public function test_parallel_requests_accumulate_their_upstream_timings(): void
    {
        $incoming = \Illuminate\Http\Request::create('/products');
        $this->app->instance('request', $incoming);

        $client = $this->client([
            Http::response('{}', 200, ['Server-Timing' => 'jsonapi_render;dur=3']),
            Http::response('{}', 200, ['Server-Timing' => 'jsonapi_render;dur=5']),
        ]);

        $client->parallel([$client->service('pim')->get('/products'), $client->service('pim')->get('/categories')]);

        $this->assertStringContainsString('pim_jsonapi_render;dur=8.000', $this->app->make(RequestTiming::class)->header());
    }
}
