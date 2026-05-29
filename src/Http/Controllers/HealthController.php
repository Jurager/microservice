<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Jurager\Microservice\Support\HealthChecker;

class HealthController extends Controller
{
    public function __construct(private readonly HealthChecker $checker) {}

    /**
     * Detailed health report (human/dashboard oriented).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $report = $this->checker->report(
            only: $request->query('service'),
            verbose: $request->boolean('verbose', config('microservice.health.verbose', false)),
        );

        return $this->respond($report, $this->checker->httpStatus($report['status']));
    }

    /**
     * Liveness probe — the process is up. No external dependencies touched.
     */
    public function live(): JsonResponse
    {
        return $this->respond(['status' => HealthChecker::STATUS_HEALTHY], 200);
    }

    /**
     * Readiness probe — the gateway can serve traffic.
     */
    public function ready(): JsonResponse
    {
        $report = $this->checker->readiness();

        return $this->respond($report, $this->checker->httpStatus($report['status']));
    }

    private function respond(array $payload, int $status): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Content-Type', 'application/health+json')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
