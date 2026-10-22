<?php

declare(strict_types=1);

namespace Jurager\Microservice\Testing;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Jurager\Microservice\Client\ServiceClient;

trait FakesServiceClient
{
    /**
     * Fake a peer service's HTTP responses for the current container-resolved ServiceClient.
     *
     * @param  list<\Psr\Http\Message\ResponseInterface|\Throwable>  $responses
     */
    public function fakeService(string $service, array $responses): void
    {
        config(["microservice.peers.$service" => "http://$service.test"]);

        app(ServiceClient::class)->swapHttpClient(
            new Client(['handler' => HandlerStack::create(new MockHandler($responses))])
        );
    }
}
