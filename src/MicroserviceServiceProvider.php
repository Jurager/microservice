<?php

declare(strict_types=1);

namespace Jurager\Microservice;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Jurager\Microservice\Bus\Connection;
use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Bus\Listener;
use Jurager\Microservice\Bus\MessageBus;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Commands\EmitCommand;
use Jurager\Microservice\Commands\EventsCommand;
use Jurager\Microservice\Commands\ListenCommand;
use Jurager\Microservice\Commands\Signing\AuthorityGenerateCommand;
use Jurager\Microservice\Commands\Signing\CertificateIssueCommand;
use Jurager\Microservice\Commands\Signing\KeygenCommand;
use Jurager\Microservice\Commands\SyncCommand;
use Jurager\Microservice\Http\Middleware\LogContext;
use Jurager\Microservice\JsonApi\ResponseError;
use Jurager\Microservice\Registry\ManifestRegistry;
use Jurager\Microservice\Registry\RouteRegistry;
use Jurager\Microservice\Support\Signer;
use RuntimeException;
use Throwable;

class MicroserviceServiceProvider extends ServiceProvider
{
    /** Register application services. */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/microservice.php', 'microservice');
        $this->normalizeServicesConfig();

        $this->app->singleton(ManifestRegistry::class);
        $this->app->singleton(Signer::class);
        $this->app->singleton(ServiceClient::class);
        $this->app->singleton(Connection::class);
        $this->app->singleton(MessageBus::class);
        $this->app->singleton(Listener::class);
        $this->app->singleton(HandlerDiscovery::class);
        $this->app->singleton(RouteRegistry::class);
    }

    /** Bootstrap application services. */
    public function boot(): void
    {
        // Console commands assert signing config for themselves where needed.
        if (! $this->app->runningInConsole()) {
            $this->validateSigningConfig();
        }

        $this->validateCache();
        $this->configureExceptions();
        $this->configureTrustedProxies();
        $this->registerLogContext();
        $this->registerSchedule();
        $this->registerMemoReset();

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'microservice');
        $this->loadRoutesFrom(__DIR__.'/../routes/microservice.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/microservice.php' => config_path('microservice.php'),
            ], 'microservice-config');

            $this->commands([
                SyncCommand::class,
                ListenCommand::class,
                EventsCommand::class,
                EmitCommand::class,
                KeygenCommand::class,
                AuthorityGenerateCommand::class,
                CertificateIssueCommand::class,
            ]);
        }
    }

    /** Normalize manifest services configuration from string to array. */
    private function normalizeServicesConfig(): void
    {
        $raw = config('microservice.manifest.services');

        if (! is_string($raw) || $raw === '') {
            return;
        }

        $services = [];

        foreach (explode(',', $raw) as $service) {
            $cleaned = trim($service);

            if ($cleaned !== '') {
                $services[] = $cleaned;
            }
        }

        config(['microservice.manifest.services' => $services]);
    }

    /** Validate the signing configuration is present, well-formed, and internally consistent. */
    private function validateSigningConfig(): void
    {
        if (config('microservice.debug', false) === true) {
            return;
        }

        try {
            $this->app->make(Signer::class)->assertConfigured();
        } catch (RuntimeException $e) {
            throw new RuntimeException("Invalid signing configuration: {$e->getMessage()}", previous: $e);
        }
    }

    /** Validate cache driver compatibility and lock support. */
    private function validateCache(): void
    {
        $driver = config('cache.default');
        $supported = ['redis', 'memcached', 'dynamodb', 'database'];

        if ($this->app->environment('testing')) {
            $supported[] = 'array';
        }

        if (! in_array($driver, $supported, true)) {
            $allowed = implode(', ', ['redis', 'memcached', 'dynamodb', 'database']);
            throw new RuntimeException("Microservice package requires a distributed cache driver. The '{$driver}' driver is not supported. Supported drivers are: {$allowed}.");
        }

        if (! $this->app['cache']->getStore() instanceof LockProvider) {
            throw new RuntimeException('The configured cache store does not support atomic locks. Microservice package requires a LockProvider (e.g., redis, memcached, database).');
        }
    }

    /** Configure exception rendering for JSON API. */
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

    /** Configure trusted proxies based on config. */
    protected function configureTrustedProxies(): void
    {
        $services = config('microservice.manifest.services', []);
        $trustAll = config('microservice.trust_all_proxies', true);

        if (empty($services) && $trustAll) {
            TrustProxies::at('*');
        }
    }

    /** Prepend LogContext to the api middleware group so request/trace IDs land in every log line. */
    protected function registerLogContext(): void
    {
        $this->callAfterResolving(Router::class, function (Router $router): void {
            $router->prependMiddlewareToGroup('api', LogContext::class);
        });
    }

    /** Clear within-request memoization cache at the end of every request. */
    private function registerMemoReset(): void
    {
        $this->app->terminating(function (): void {
            if ($this->app->resolved(ServiceClient::class)) {
                $this->app->make(ServiceClient::class)->resetMemo();
            }
        });
    }

    /** Register console schedule for manifest synchronization. */
    protected function registerSchedule(): void
    {
        $interval = (int) config('microservice.manifest.sync_interval', 5);
        $services = config('microservice.manifest.services', []);

        if ($interval <= 0 || empty($services)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($interval): void {
            $schedule->command(SyncCommand::class)->cron("*/{$interval} * * * *");
        });
    }
}
