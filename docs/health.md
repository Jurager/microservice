---
title: Checking service health
weight: 41
---

You can check service status through these endpoints:

| Endpoint | Purpose |
|---|---|
| `/microservice/health` | Detailed health report |
| `/microservice/health/live` | Liveness probe |
| `/microservice/health/ready` | Readiness probe |
| `/microservice/metrics` | Prometheus metrics |

Change URLs in `microservice.health`, or set an endpoint to `null` to disable it. Liveness checks the process; readiness checks critical dependencies and available manifests.

Detailed reports and metrics are cached for `SERVICE_HEALTH_CACHE_TTL` seconds (default `15`). Probes are not cached.

Reports return `503` when a critical dependency or service manifest is unavailable. Non-critical failures and open circuits produce `degraded` with `200`.

Infrastructure details are hidden by default. Enable `SERVICE_HEALTH_VERBOSE` to include them. Use `?verbose=0` to hide details or `?service=orders` to filter services.

## Dependency checks

Add a `DependencyCheck` implementation to `microservice.health.checks` to probe another dependency:

```php
use Illuminate\Support\Facades\DB;
use Jurager\Microservice\Health\Contracts\DependencyCheck;
use Jurager\Microservice\Health\DependencyStatus;

class DatabaseCheck implements DependencyCheck
{
    public function name(): string
    {
        return 'database';
    }

    public function critical(): bool
    {
        return true;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function check(): DependencyStatus
    {
        $started = microtime(true);

        try {
            DB::select('select 1');

            return DependencyStatus::up(round((microtime(true) - $started) * 1000, 2));
        } catch (Throwable $e) {
            return DependencyStatus::down($e);
        }
    }
}
```

```php
// config/microservice.php
'health' => [
    'checks' => [
        \Jurager\Microservice\Health\Checks\CacheCheck::class,
        \Jurager\Microservice\Health\Checks\BrokerCheck::class,
        \App\Health\DatabaseCheck::class,
    ],
],
```

Failed critical checks also fail readiness. Other failed checks mark the report as degraded.

The metrics endpoint exposes the same report as Prometheus gauges.

```
# HELP microservice_up Overall gateway status (1=healthy, 0.5=degraded, 0=unhealthy).
# TYPE microservice_up gauge
microservice_up 0.5
# HELP microservice_service_up Service manifest availability (1=present, 0=missing).
# TYPE microservice_service_up gauge
microservice_service_up{service="orders"} 1
microservice_service_up{service="catalog"} 0
# HELP microservice_dead_letter_messages Messages sitting in each dead-letter queue.
# TYPE microservice_dead_letter_messages gauge
microservice_dead_letter_messages{queue="api.catalog.product.updated.dlq"} 0
```

> [!NOTE]
> Health endpoints are public by default. Protect reports and metrics at the route level if they should only be visible inside your infrastructure.
