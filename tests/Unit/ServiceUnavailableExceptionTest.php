<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use PHPUnit\Framework\TestCase;

class ServiceUnavailableExceptionTest extends TestCase
{
    public function test_default_message_includes_service_name(): void
    {
        $exception = new ServiceUnavailableException('oms');

        $this->assertStringContainsString('oms', $exception->getMessage());
    }

    public function test_custom_message_overrides_default(): void
    {
        $exception = new ServiceUnavailableException('oms', 'No instances configured.');

        $this->assertSame('No instances configured.', $exception->getMessage());
    }

    public function test_previous_exception_is_preserved(): void
    {
        $previous = new \RuntimeException('connect failed');
        $exception = new ServiceUnavailableException('oms', '', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_service_property_is_accessible(): void
    {
        $exception = new ServiceUnavailableException('oms');

        $this->assertSame('oms', $exception->service);
    }
}
