<?php

declare(strict_types=1);

namespace Jurager\Microservice;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Jurager\Microservice\Bus\Connection;
use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Bus\Listener;
use Jurager\Microservice\Bus\MessageBus;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Commands\EmitCommand;
use Jurager\Microservice\Commands\EventsCommand;
use Jurager\Microservice\Commands\ListenCommand;
use Jurager\Microservice\Commands\SyncManifestsCommand;
use Jurager\Microservice\JsonApi\ResponseError;
use Jurager\Microservice\Registry\ManifestRegistry;
use Jurager\Microservice\Registry\RouteRegistry;
use Jurager\Microservice\Support\HmacSigner;
use RuntimeException;
use Throwable;

class MicroserviceServiceProvider extends ServiceProvider
{
    /** Register application services. */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/microservice.php', 'microservice');
        $this->normalizeServicesConfig();

        $this->app->singleton(HmacSigner::class);
        $this->app->singleton(ServiceClient::class);
        $this->app->singleton(Connection::class);
        $this->app->singleton(MessageBus::class);
        $this->app->singleton(Listener::class);
        $this->app->singleton(HandlerDiscovery::class);
        $this->app->singleton(RouteRegistry::class);
        $this->app->singleton(ManifestRegistry::class);
    }

    /** Bootstrap application services. */
    public function boot(): void
    {
        $this->validateSecret();
        $this->validateCache();
        $this->configureExceptions();
        $this->configureTrustedProxies();
        $this->registerSchedule();
        $this->registerBusHeartbeat();

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'microservice');
        $this->loadRoutesFrom(__DIR__.'/../routes/microservice.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/microservice.php' => config_path('microservice.php'),
            ], 'microservice-config');

            $this->commands([
                SyncManifestsCommand::class,
                ListenCommand::class,
                EventsCommand::class,
                EmitCommand::class,
            ]);
        }
    }

    /** Normalize manifest services configuration. */
    private function normalizeServicesConfig(): void
    {
        $raw = config('microservice.manifest.services');

        if (is_string($raw) && $raw !== '') {
            config(['microservice.manifest.services' => array_values(array_filter(array_map('trim', explode(',', $raw))))]);
        }
    }

    /** Validate HMAC shared secret. */
    private function validateSecret(): void
    {
        if (config('microservice.debug', false)) {
            return;
        }

        if (blank(config('microservice.secret'))) {
            throw new RuntimeException(
                'Invalid SERVICE_SECRET value. HMAC signing requires a non-empty shared secret.'
            );
        }
    }

    /** Validate cache driver compatibility. */
    private function validateCache(): void
    {
        $driver = config('cache.default');
        $supported = ['redis', 'memcached', 'dynamodb', 'database'];

        if ($this->app->environment('testing')) {
            $supported[] = 'array';
        }

        if (! in_array($driver, $supported, true)) {
            $allowed = implode(', ', ['redis', 'memcached', 'dynamodb', 'database']);

            throw new RuntimeException(
                "Microservice package requires a distributed cache driver. The '{$driver}' driver is not supported. Supported drivers are: {$allowed}."
            );
        }

        if (! $this->app['cache']->getStore() instanceof LockProvider) {
            throw new RuntimeException(
                'The configured cache store does not support atomic locks. Microservice package requires a LockProvider (e.g., redis, memcached, database).'
            );
        }
    }

    /** Configure exception rendering. */
    private function configureExceptions(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            $handler->renderable(function (Throwable $e, Request $request): ?JsonResponse {
                if ($request->wantsJson() || $request->is('api/*')) {
                    return ResponseError::fromException($e);
                }

                return null;
            });
        });
    }

    /** Configure trusted proxies. */
    protected function configureTrustedProxies(): void
    {
        $services = config('microservice.manifest.services', []);
        $trustAll = config('microservice.trust_all_proxies', true);

        if (empty($services) && $trustAll) {
            TrustProxies::at('*');
        }
    }

    /** Register console schedule. */
    protected function registerSchedule(): void
    {
        $interval = (int) config('microservice.manifest.sync_interval', 5);
        $services = config('microservice.manifest.services', []);

        if ($interval <= 0 || empty($services)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($interval): void {
            $schedule->command(SyncManifestsCommand::class)->cron("*/{$interval} * * * *");
        });
    }

    /**
     * Keep the bus connection's AMQP heartbeat alive between HTTP requests.
     *
     * Request-response Octane workers publish through a connection they never
     * otherwise read from, so nothing services the broker's heartbeat frames
     * between requests — see Connection::pulse(). No-op outside Octane (the
     * tick event this listens for simply never fires).
     */
    protected function registerBusHeartbeat(): void
    {
        // laravel/octane is an optional integration, not a package dependency —
        // reference it only by string/FQCN inside this guard so nothing here
        // requires the class to exist when it isn't installed.
        if (! class_exists('Laravel\\Octane\\Facades\\Octane')) {
            return;
        }

        $heartbeat = (int) config('microservice.bus.connection.heartbeat', 60);
        $interval = max(5, intdiv($heartbeat, 3));

        \Laravel\Octane\Facades\Octane::tick('microservice-bus-heartbeat', function (): void {
            $this->app->make(Connection::class)->pulse();
        }, seconds: $interval);
    }
}