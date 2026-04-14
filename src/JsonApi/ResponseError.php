<?php

declare(strict_types=1);

namespace Jurager\Microservice\JsonApi;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Converts exceptions to structured JSON error responses.
 *
 * By default renders JSON:API-compliant errors. Override the renderer
 * to produce any format your project needs:
 *
 *   ResponseError::renderUsing(function (array $errors, int $status, array $headers): JsonResponse {
 *       return new JsonResponse(['errors' => $errors], $status, $headers);
 *   });
 *
 * Usage in bootstrap/app.php:
 *
 *   ->withExceptions(function (Exceptions $exceptions): void {
 *       $exceptions->render(fn (Throwable $e) => ResponseError::fromException($e));
 *   })
 */
final class ResponseError
{
    /** @var callable(array, int, array): JsonResponse|null */
    private static $renderer = null;

    private function __construct() {}

    /**
     * Override the default JSON:API renderer.
     *
     * @param  callable(array $errors, int $status, array $headers): JsonResponse  $renderer
     */
    public static function renderUsing(callable $renderer): void
    {
        self::$renderer = $renderer;
    }

    public static function fromException(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return self::validation($e);
        }

        $status = match (true) {
            $e instanceof HttpExceptionInterface  => $e->getStatusCode(),
            $e instanceof ServiceRequestException => $e->status,
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException  => 403,
            $e instanceof ModelNotFoundException  => 404,
            default                               => 500,
        };

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return self::render(
            errors: [[
                'status' => (string) $status,
                'title'  => self::title($status),
                'detail' => $e->getMessage() ?: self::detail($status),
            ]],
            status: $status,
            headers: $headers,
        );
    }

    /** 422 with per-field pointer sources, e.g. `/data/attributes/email`. */
    private static function validation(ValidationException $e): JsonResponse
    {
        $errors = [];

        foreach ($e->errors() as $field => $messages) {
            $pointer = '/data/attributes/'.str_replace('.', '/', $field);

            foreach ($messages as $message) {
                $errors[] = [
                    'status' => '422',
                    'title'  => 'Validation Error',
                    'detail' => $message,
                    'source' => ['pointer' => $pointer],
                ];
            }
        }

        if (empty($errors)) {
            $errors = [['status' => '422', 'title' => 'Validation Error', 'detail' => $e->getMessage()]];
        }

        return self::render(errors: $errors, status: 422);
    }

    private static function render(array $errors, int $status, array $headers = []): JsonResponse
    {
        if (self::$renderer !== null) {
            return (self::$renderer)($errors, $status, $headers);
        }

        // Default: JSON:API format
        return new JsonResponse(
            data: ['errors' => $errors],
            status: $status,
            headers: array_merge(['Content-Type' => 'application/vnd.api+json'], $headers),
        );
    }

    private static function title(int $status): string
    {
        return match ($status) {
            401     => 'Unauthenticated',
            403     => 'Forbidden',
            404     => 'Not Found',
            405     => 'Method Not Allowed',
            422     => 'Unprocessable Entity',
            429     => 'Too Many Requests',
            500     => 'Server Error',
            default => 'HTTP Error',
        };
    }

    private static function detail(int $status): string
    {
        return match ($status) {
            401     => 'Unauthenticated.',
            403     => 'This action is unauthorized.',
            404     => 'Resource not found.',
            default => 'An unexpected error occurred.',
        };
    }
}
