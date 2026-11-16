<?php

declare(strict_types=1);

namespace Jurager\Microservice\Testing;

use Jurager\Microservice\Facades\Microservice;

trait FakesServiceClient
{
    /**
     * Fake responses for a service: a list in request order or a map of routes.
     *
     * @param  list<mixed>|array<string, mixed>  $responses
     */
    public function fakeService(string $service, array $responses): FakeServices
    {
        return Microservice::fake([$service => $responses]);
    }
}
