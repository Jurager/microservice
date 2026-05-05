<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\Exceptions\DuplicateRequestException;
use Jurager\Microservice\Exceptions\InvalidCacheStateException;
use Jurager\Microservice\Exceptions\InvalidRequestIdException;
use Jurager\Microservice\Exceptions\InvalidSignatureException;
use Jurager\Microservice\Exceptions\MissingServiceNameException;
use Jurager\Microservice\Exceptions\MissingSignatureException;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use PHPUnit\Framework\TestCase;

class HttpExceptionsTest extends TestCase
{
    public function test_duplicate_request_exception_status_and_default_message(): void
    {
        $e = new DuplicateRequestException();
        $this->assertSame(409, $e->getStatusCode());
        $this->assertSame('Request is already being processed.', $e->getMessage());
    }

    public function test_duplicate_request_exception_accepts_custom_message(): void
    {
        $e = new DuplicateRequestException('Duplicate.');
        $this->assertSame('Duplicate.', $e->getMessage());
    }

    public function test_invalid_cache_state_exception_status_and_default_message(): void
    {
        $e = new InvalidCacheStateException();
        $this->assertSame(500, $e->getStatusCode());
        $this->assertSame('Invalid cache state.', $e->getMessage());
    }

    public function test_invalid_cache_state_exception_accepts_custom_message(): void
    {
        $e = new InvalidCacheStateException('Corrupted.');
        $this->assertSame('Corrupted.', $e->getMessage());
    }

    public function test_invalid_request_id_exception_status_and_default_message(): void
    {
        $e = new InvalidRequestIdException();
        $this->assertSame(400, $e->getStatusCode());
        $this->assertStringContainsString('UUID v4', $e->getMessage());
    }

    public function test_invalid_request_id_exception_accepts_custom_message(): void
    {
        $msg = 'X-Request-Id must be a valid UUID v4. Received: bad';
        $e   = new InvalidRequestIdException($msg);
        $this->assertSame($msg, $e->getMessage());
    }

    public function test_invalid_signature_exception_status_and_default_message(): void
    {
        $e = new InvalidSignatureException();
        $this->assertSame(401, $e->getStatusCode());
        $this->assertSame('Invalid signature or timestamp.', $e->getMessage());
    }

    public function test_invalid_signature_exception_accepts_custom_message(): void
    {
        $e = new InvalidSignatureException('Bad sig.');
        $this->assertSame('Bad sig.', $e->getMessage());
    }

    public function test_missing_service_name_exception_status_and_default_message(): void
    {
        $e = new MissingServiceNameException();
        $this->assertSame(401, $e->getStatusCode());
        $this->assertSame('Missing service name header.', $e->getMessage());
    }

    public function test_missing_signature_exception_status_and_default_message(): void
    {
        $e = new MissingSignatureException();
        $this->assertSame(401, $e->getStatusCode());
        $this->assertSame('Missing signature headers.', $e->getMessage());
    }

    public function test_service_request_exception_defaults(): void
    {
        $e = new ServiceRequestException(404);
        $this->assertSame(404, $e->status);
        $this->assertNull($e->errors);
        $this->assertSame('Service request failed with status 404.', $e->getMessage());
    }

    public function test_service_request_exception_with_errors_array(): void
    {
        $errors = [['status' => '422', 'detail' => 'Name is required']];
        $e      = new ServiceRequestException(422, errors: $errors);
        $this->assertSame($errors, $e->errors);
    }

    public function test_service_request_exception_with_custom_message(): void
    {
        $e = new ServiceRequestException(503, 'Service down.');
        $this->assertSame('Service down.', $e->getMessage());
    }
}
