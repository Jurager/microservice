<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Jurager\Microservice\Client\ServiceResponse;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use PHPUnit\Framework\TestCase;

class ServiceRequestExceptionTest extends TestCase
{
    public function test_default_message_includes_status_code(): void
    {
        $response = new ServiceResponse(new Response(422));
        $exception = new ServiceRequestException($response);

        $this->assertStringContainsString('422', $exception->getMessage());
    }

    public function test_custom_message_overrides_default(): void
    {
        $response = new ServiceResponse(new Response(500));
        $exception = new ServiceRequestException($response, 'Custom error');

        $this->assertSame('Custom error', $exception->getMessage());
    }

    public function test_response_property_is_accessible(): void
    {
        $response = new ServiceResponse(new Response(500));
        $exception = new ServiceRequestException($response);

        $this->assertSame($response, $exception->response);
    }
}
