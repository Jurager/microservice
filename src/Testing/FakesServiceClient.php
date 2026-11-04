<?php

declare(strict_types=1);

namespace Jurager\Microservice\Testing;

use Jurager\Microservice\Facades\Microservice;

trait FakesServiceClient
{
    /**
     * Queue fake responses for a service.
     *
     * @param  list<mixed>  $responses
     */
    public function fakeService(string $service, array $responses): FakeServices
    {
        return Microservice::fake([$service => $responses]);
    }
}
