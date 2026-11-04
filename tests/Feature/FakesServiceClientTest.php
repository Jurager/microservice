<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Facades\Microservice;
use Jurager\Microservice\Testing\FakesServiceClient;
use Jurager\Microservice\Tests\TestCase;

class FakesServiceClientTest extends TestCase
{
    use FakesServiceClient;

    public function test_fake_service_registers_the_service_as_a_peer_and_fakes_its_responses(): void
    {
        $this->fakeService('oms', [Http::response(['data' => 'faked'])]);

        $response = $this->app->make(ServiceClient::class)->service('oms')->get('/api/orders')->send();

        $this->assertSame('faked', $response->json('data'));
    }

    public function test_responses_are_consumed_in_order_and_may_be_psr_responses_or_statuses(): void
    {
        $this->fakeService('oms', [new Response(200, [], '{"data":"first"}'), 404]);

        $client = $this->app->make(ServiceClient::class);

        $this->assertSame('first', $client->service('oms')->get('/api/orders/1')->send()->json('data'));
        $this->assertSame(404, $client->service('oms')->get('/api/orders/2')->withoutThrowing()->send()->status());
    }

    public function test_a_throwable_simulates_an_unreachable_service(): void
    {
        $this->fakeService('oms', [new ConnectException('refused', new PsrRequest('GET', 'http://oms.test'))]);

        $this->expectException(ServiceUnavailableException::class);

        Microservice::service('oms')->get('/api/orders')->send();
    }

    public function test_faking_a_service_again_appends_to_its_queue(): void
    {
        $this->fakeService('oms', [Http::response(['n' => 1])]);
        $this->fakeService('oms', [Http::response(['n' => 2])]);

        $this->assertSame(1, Microservice::service('oms')->get('/a')->send()->json('n'));
        $this->assertSame(2, Microservice::service('oms')->get('/b')->send()->json('n'));
    }

    public function test_fake_service_does_not_touch_other_peers(): void
    {
        $this->app['config']->set('microservice.peers', ['pim' => 'http://pim.test']);

        $this->fakeService('oms', [Http::response()]);

        $this->assertSame('http://pim.test', $this->app['config']->get('microservice.peers.pim'));
    }

    public function test_several_services_are_faked_with_their_own_responses(): void
    {
        Microservice::fake([
            'oms' => [Http::response(['data' => 'orders'])],
            'pim' => [Http::response(['data' => 'products'])],
        ]);

        $this->assertSame('products', Microservice::service('pim')->get('/v1/products')->send()->json('data'));
        $this->assertSame('orders', Microservice::service('oms')->get('/v1/orders')->send()->json('data'));

        Microservice::assertSentCount(2);
        Microservice::assertSent(fn (Request $request, string $service) => $service === 'pim' && $request->url() === 'http://pim.test/v1/products');
        Microservice::assertNotSent(fn (Request $request, string $service) => $service === 'sfm');
    }

    public function test_requests_of_the_application_itself_are_not_counted(): void
    {
        Microservice::fake(['oms' => [Http::response()]]);
        Http::fake(['example.com/*' => Http::response()]);

        Http::get('https://example.com/ping');

        Microservice::assertNothingSent();
    }
}
