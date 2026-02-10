<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\Events\ServiceRequestFailed;
use PHPUnit\Framework\TestCase;

class ServiceRequestFailedEventTest extends TestCase
{
    public function test_constructor_assigns_all_properties(): void
    {
        $event = new ServiceRequestFailed(
            service: 'oms',
            url: 'http://oms:8000',
            method: 'GET',
            path: '/api/orders',
            statusCode: 500,
            message: 'Server error',
        );

        $this->assertSame('oms', $event->service);
        $this->assertSame('http://oms:8000', $event->url);
        $this->assertSame('GET', $event->method);
        $this->assertSame('/api/orders', $event->path);
        $this->assertSame(500, $event->statusCode);
        $this->assertSame('Server error', $event->message);
    }
}
