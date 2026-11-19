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

## Queue workers

Laravel's `--timeout` is a signal handler, and PHP runs it only between PHP operations. A job stuck inside a C call, such as a query waiting on a lock, is never stopped by it.

Set `QUEUE_WORKER_HEARTBEAT` and the worker touches that file before each poll of the queue and when a job starts:

```bash
QUEUE_WORKER_HEARTBEAT=/tmp/worker.heartbeat php artisan queue:work --timeout=120
```

The file is not touched while a job runs, so set the probe limit above your longest job:

```yaml
livenessProbe:
  exec:
    command:
      - /bin/sh
      - -c
      - test $(( $(date +%s) - $(stat -c %Y /tmp/worker.heartbeat) )) -lt 300
  initialDelaySeconds: 30
  periodSeconds: 30
  failureThreshold: 3
```

A paused worker, or one in maintenance mode without `--force`, stops touching the file.

> [!NOTE]
> Health endpoints are public by default. Protect reports and metrics at the route level if they should only be visible inside your infrastructure.
