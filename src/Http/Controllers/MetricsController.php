<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Jurager\Microservice\Support\HealthChecker;

/**
 * Prometheus text exposition of the gateway health report.
 *
 * JSON is for humans; Prometheus wants numbers it can alert on.
 */
class MetricsController extends Controller
{
    private const array OVERALL = [
        HealthChecker::STATUS_HEALTHY => 1,
        HealthChecker::STATUS_DEGRADED => 0.5,
        HealthChecker::STATUS_UNHEALTHY => 0,
    ];

    public function __construct(private readonly HealthChecker $checker) {}

    public function __invoke(): Response
    {
        $report = $this->checker->report(verbose: false);

        $lines = [
            ...$this->gauge(
                'microservice_up',
                'Overall gateway status (1=healthy, 0.5=degraded, 0=unhealthy).',
                [['', self::OVERALL[$report['status']] ?? 0]],
            ),
            ...$this->dependencyMetrics($report['dependencies']),
            ...$this->serviceMetrics($report['services']),
            ...$this->deadLetterMetrics($report['dependencies']['rabbitmq']['dead_letters'] ?? []),
        ];

        return response(implode("\n", $lines)."\n", 200)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @param  array<string, array<string, mixed>>  $dependencies
     * @return string[]
     */
    private function dependencyMetrics(array $dependencies): array
    {
        $samples = [];

        foreach ($dependencies as $name => $dependency) {
            $samples[] = [
                sprintf('{dependency="%s"}', $name),
                ($dependency['status'] ?? null) === 'up' ? 1 : 0,
            ];
        }

        return $this->gauge('microservice_dependency_up', 'Gateway dependency reachability.', $samples);
    }

    /**
     * @param  array<string, array<string, mixed>>  $services
     * @return string[]
     */
    private function serviceMetrics(array $services): array
    {
        $up = $stale = $routes = $age = $circuit = [];

        foreach ($services as $service => $data) {
            $label = sprintf('{service="%s"}', $service);

            $up[] = [$label, $data['status'] !== 'missing' ? 1 : 0];
            $stale[] = [$label, $data['status'] === 'stale' ? 1 : 0];
            $routes[] = [$label, $data['routes_count']];

            if (isset($data['age_seconds'])) {
                $age[] = [$label, $data['age_seconds']];
            }

            if (isset($data['circuit'])) {
                $circuit[] = [$label, $data['circuit']['state'] === 'open' ? 1 : 0];
            }
        }

        return [
            ...$this->gauge('microservice_service_up', 'Service manifest availability (1=present, 0=missing).', $up),
            ...$this->gauge('microservice_service_stale', 'Whether the service manifest is stale.', $stale),
            ...$this->gauge('microservice_service_routes_count', 'Number of routes in the service manifest.', $routes),
            ...$this->gauge('microservice_service_manifest_age_seconds', 'Seconds since the manifest was last synced.', $age),
            ...$this->gauge('microservice_service_circuit_open', 'Whether the circuit breaker is open for the service.', $circuit),
        ];
    }

    /**
     * @param  array<string, int>  $deadLetters
     * @return string[]
     */
    private function deadLetterMetrics(array $deadLetters): array
    {
        $samples = [];

        foreach ($deadLetters as $queue => $depth) {
            $samples[] = [sprintf('{queue="%s"}', $queue), $depth];
        }

        return $this->gauge('microservice_dead_letter_messages', 'Messages sitting in each dead-letter queue.', $samples);
    }

    /**
     * @param  array<int, array{0: string, 1: int|float}>  $samples
     * @return string[]
     */
    private function gauge(string $name, string $help, array $samples): array
    {
        if ($samples === []) {
            return [];
        }

        $lines = [
            "# HELP $name $help",
            "# TYPE $name gauge",
        ];

        foreach ($samples as [$labels, $value]) {
            $lines[] = trim("$name$labels")." $value";
        }

        return $lines;
    }
}
