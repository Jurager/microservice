<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Testing\FakesServiceClient;
use Jurager\Microservice\Tests\TestCase;

class FakesServiceClientTest extends TestCase
{
    use FakesServiceClient;

    public function test_fake_service_registers_the_service_as_a_peer_and_fakes_its_responses(): void
    {
        $this->fakeService('oms', [new Response(200, [], '{"data":"faked"}')]);

        $response = $this->app->make(ServiceClient::class)->service('oms')->get('/api/orders')->send();

        $this->assertSame('faked', $response->json('data'));
    }

    public function test_fake_service_responses_are_consumed_in_order(): void
    {
        $this->fakeService('oms', [
            new Response(200, [], '{"data":"first"}'),
            new Response(404),
        ]);

        $client = $this->app->make(ServiceClient::class);

        $first = $client->service('oms')->get('/api/orders/1')->send();
        $second = $client->service('oms')->get('/api/orders/2')->withoutThrowing()->send();

        $this->assertSame('first', $first->json('data'));
        $this->assertSame(404, $second->status());
    }

    public function test_fake_service_does_not_touch_other_peers(): void
    {
        $this->app['config']->set('microservice.peers', ['pim' => 'http://pim.test']);

        $this->fakeService('oms', [new Response(200, [], '{"data":"faked"}')]);

        $this->assertSame('http://pim.test', $this->app['config']->get('microservice.peers.pim'));
    }
}
