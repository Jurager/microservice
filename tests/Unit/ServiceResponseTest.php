<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Jurager\Microservice\Client\ServiceResponse;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use PHPUnit\Framework\TestCase;

class ServiceResponseTest extends TestCase
{
    public function test_status_returns_response_status_code(): void
    {
        $response = new ServiceResponse(new Response(201));

        $this->assertSame(201, $response->status());
    }

    public function test_ok_returns_true_for_2xx(): void
    {
        $this->assertTrue((new ServiceResponse(new Response(200)))->ok());
        $this->assertTrue((new ServiceResponse(new Response(204)))->ok());
        $this->assertTrue((new ServiceResponse(new Response(299)))->ok());
    }

    public function test_ok_returns_false_for_non_2xx(): void
    {
        $this->assertFalse((new ServiceResponse(new Response(301)))->ok());
        $this->assertFalse((new ServiceResponse(new Response(404)))->ok());
        $this->assertFalse((new ServiceResponse(new Response(500)))->ok());
    }

    public function test_failed_returns_inverse_of_ok(): void
    {
        $this->assertFalse((new ServiceResponse(new Response(200)))->failed());
        $this->assertTrue((new ServiceResponse(new Response(500)))->failed());
    }

    public function test_body_returns_string_content(): void
    {
        $response = new ServiceResponse(new Response(200, [], '{"key":"value"}'));

        $this->assertSame('{"key":"value"}', $response->body());
    }

    public function test_json_parses_body_as_array(): void
    {
        $response = new ServiceResponse(new Response(200, [], '{"data":{"id":1}}'));

        $this->assertSame(['data' => ['id' => 1]], $response->json());
    }

    public function test_json_with_key_uses_dot_notation(): void
    {
        $response = new ServiceResponse(new Response(200, [], '{"data":{"id":1}}'));

        $this->assertSame(1, $response->json('data.id'));
        $this->assertSame('default', $response->json('missing', 'default'));
    }

    public function test_json_returns_empty_array_for_invalid_json(): void
    {
        $response = new ServiceResponse(new Response(200, [], 'not json'));

        $this->assertSame([], $response->json());
    }

    public function test_json_returns_empty_array_for_non_array_json(): void
    {
        $response = new ServiceResponse(new Response(200, [], '"just a string"'));

        $this->assertSame([], $response->json());
    }

    public function test_json_caches_decoded_result(): void
    {
        $response = new ServiceResponse(new Response(200, [], '{"a":1}'));

        $first = $response->json();
        $second = $response->json();

        $this->assertSame($first, $second);
        $this->assertSame(1, $response->json('a'));
    }

    public function test_body_is_readable_multiple_times(): void
    {
        $response = new ServiceResponse(new Response(200, [], '{"data":"test"}'));

        $this->assertSame('{"data":"test"}', $response->body());
        $this->assertSame('{"data":"test"}', $response->body());
    }

    public function test_header_returns_header_value(): void
    {
        $response = new ServiceResponse(new Response(200, ['X-Total' => '42']));

        $this->assertSame('42', $response->header('X-Total'));
    }

    public function test_header_returns_null_when_missing(): void
    {
        $response = new ServiceResponse(new Response(200));

        $this->assertNull($response->header('X-Missing'));
    }

    public function test_headers_returns_all_headers(): void
    {
        $response = new ServiceResponse(new Response(200, ['Content-Type' => 'application/json']));

        $headers = $response->headers();

        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertSame(['application/json'], $headers['Content-Type']);
    }

    public function test_throw_throws_exception_on_failure(): void
    {
        $response = new ServiceResponse(new Response(500));

        $this->expectException(ServiceRequestException::class);

        $response->throw();
    }

    public function test_throw_returns_self_on_success(): void
    {
        $response = new ServiceResponse(new Response(200));

        $this->assertSame($response, $response->throw());
    }

    public function test_to_psr_response_returns_original(): void
    {
        $psr = new Response(200);
        $response = new ServiceResponse($psr);

        $this->assertSame($psr, $response->toPsrResponse());
    }
}
