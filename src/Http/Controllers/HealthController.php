<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Controllers;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Jurager\Microservice\Health\HealthChecker;

class HealthController extends Controller
{
    /** @param  bool  $verbose  Whether infrastructure details may be shown at all. */
    public function __construct(
        private readonly HealthChecker $checker,
        #[Config('microservice.health.verbose', false)] private readonly bool $verbose = false,
    ) {
    }

    /**
     * Detailed health report (human/dashboard oriented).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $only = $request->query('service');

        // Include infrastructure details only when enabled and requested.
        $verbose = $this->verbose && $request->boolean('verbose', true);

        $report = $this->checker->report(
            only: is_string($only) && $only !== '' ? $only : null,
            verbose: $verbose,
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
