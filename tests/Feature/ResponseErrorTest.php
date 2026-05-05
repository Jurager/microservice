<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use Jurager\Microservice\JsonApi\ResponseError;
use Jurager\Microservice\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResponseErrorTest extends TestCase
{
    protected function tearDown(): void
    {
        $ref = new \ReflectionProperty(ResponseError::class, 'renderer');
        $ref->setValue(null, null);

        parent::tearDown();
    }

    public function test_http_exception_uses_its_status_code(): void
    {
        $response = ResponseError::fromException(new HttpException(403, 'Forbidden.'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertErrorDetail($response, 'Forbidden.');
    }

    public function test_authentication_exception_returns_401(): void
    {
        $response = ResponseError::fromException(new AuthenticationException());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_authorization_exception_returns_403(): void
    {
        $response = ResponseError::fromException(new AuthorizationException('Not allowed.'));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_model_not_found_exception_returns_404(): void
    {
        $response = ResponseError::fromException(new ModelNotFoundException());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_generic_exception_returns_500(): void
    {
        $response = ResponseError::fromException(new \RuntimeException('Unexpected.'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertErrorDetail($response, 'Unexpected.');
    }

    public function test_service_request_exception_with_errors_forwards_them(): void
    {
        $errors   = [['status' => '422', 'title' => 'Validation Error', 'detail' => 'Name required']];
        $response = ResponseError::fromException(new ServiceRequestException(422, errors: $errors));

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        $this->assertSame($errors, $payload['errors']);
    }

    public function test_service_request_exception_without_errors_renders_detail(): void
    {
        $response = ResponseError::fromException(new ServiceRequestException(503));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertErrorDetail($response, 'Service request failed with status 503.');
    }

    public function test_validation_exception_returns_422_with_per_field_errors(): void
    {
        $response = ResponseError::fromException(
            ValidationException::withMessages(['name' => ['The name field is required.']])
        );

        $this->assertSame(422, $response->getStatusCode());

        $payload = json_decode($response->getContent(), true);
        $this->assertNotEmpty($payload['errors']);
        $this->assertSame('422', $payload['errors'][0]['status']);
        $this->assertSame('/data/attributes/name', $payload['errors'][0]['source']['pointer']);
        $this->assertSame('The name field is required.', $payload['errors'][0]['detail']);
    }

    public function test_validation_exception_with_nested_field_uses_slash_pointer(): void
    {
        $response = ResponseError::fromException(
            ValidationException::withMessages(['data.address' => ['Invalid.']])
        );

        $payload = json_decode($response->getContent(), true);
        $this->assertSame('/data/attributes/data/address', $payload['errors'][0]['source']['pointer']);
    }

    public function test_default_render_returns_json_api_format(): void
    {
        $response = ResponseError::fromException(new \RuntimeException('Oops.'));

        $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        $payload = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $payload);
        $this->assertSame('500', $payload['errors'][0]['status']);
        $this->assertSame('Server Error', $payload['errors'][0]['title']);
    }

    public function test_render_using_overrides_default_format(): void
    {
        ResponseError::renderUsing(fn (array $errors, int $status) => new JsonResponse(
            ['message' => $errors[0]['detail']],
            $status
        ));

        $response = ResponseError::fromException(new \RuntimeException('Custom format.'));

        $payload = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('message', $payload);
        $this->assertSame('Custom format.', $payload['message']);
    }

    public function test_render_using_receives_headers(): void
    {
        $capturedHeaders = null;

        ResponseError::renderUsing(function (array $errors, int $status, array $headers) use (&$capturedHeaders): JsonResponse {
            $capturedHeaders = $headers;
            return new JsonResponse(['errors' => $errors], $status, $headers);
        });

        ResponseError::fromException(new HttpException(401, 'Unauth.', null, ['WWW-Authenticate' => 'Bearer']));

        $this->assertArrayHasKey('WWW-Authenticate', $capturedHeaders);
    }

    private function assertErrorDetail(JsonResponse $response, string $detail): void
    {
        $payload = json_decode($response->getContent(), true);
        $this->assertSame($detail, $payload['errors'][0]['detail']);
    }
}
