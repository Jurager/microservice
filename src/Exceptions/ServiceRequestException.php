<?php

declare(strict_types=1);

namespace Jurager\Microservice\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A non-successful response received from another service.
 */
class ServiceRequestException extends HttpException
{
    /**
     * Upstream statuses that say nothing about the client's own request.
     */
    private const array NOT_THE_CLIENTS = [401, 407];

    /**
     * @param  int  $status  The status the service answered with.
     * @param  array<int, array<string, mixed>>|null  $errors  The JSON:API errors the service answered with.
     */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly ?array $errors = null,
        public readonly ?string $service = null,
    ) {
        parent::__construct(
            self::statusFor($status),
            $message ?: ($service !== null ? "Service [$service] request failed with status $status." : "Service request failed with status $status."),
        );
    }

    /**
     * The status the client is answered with for an upstream status.
     */
    public static function statusFor(int $status): int
    {
        return $status >= 500 || in_array($status, self::NOT_THE_CLIENTS, true) ? 502 : $status;
    }

    /**
     * Whether the upstream errors are rendered to the client: only when its status is passed through.
     */
    public function rendersUpstreamErrors(): bool
    {
        return $this->errors !== null && $this->getStatusCode() === $this->status;
    }

    /** @return array{upstream_service: string|null, upstream_status: int, http_status: int} */
    public function context(): array
    {
        return [
            'upstream_service' => $this->service,
            'upstream_status' => $this->status,
            'http_status' => $this->getStatusCode(),
        ];
    }
}
