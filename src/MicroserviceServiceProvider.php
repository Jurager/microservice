<?php

declare(strict_types=1);

namespace Jurager\Microservice;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Jurager\Microservice\Bus\Connection;
use Jurager\Microservice\Bus\ConnectionFactory;
use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Bus\Listener;
use Jurager\Microservice\Bus\MessageBus;
use Jurager\Microservice\Client\CircuitBreaker;
use Jurager\Microservice\Client\ResponseMemo;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Client\ServiceDiscovery;
use Jurager\Microservice\Commands\EmitCommand;
use Jurager\Microservice\Commands\EventsCommand;
use Jurager\Microservice\Commands\ListenCommand;
use Jurager\Microservice\Commands\Signing\AuthorityGenerateCommand;
use Jurager\Microservice\Commands\Signing\CertificateIssueCommand;
use Jurager\Microservice\Commands\Signing\KeygenCommand;
use Jurager\Microservice\Commands\SyncCommand;
use Jurager\Microservice\Health\Checks\CacheCheck;
use Jurager\Microservice\Health\HealthChecker;
use Jurager\Microservice\Http\Middleware\Idempotency;
use Jurager\Microservice\Http\Middleware\JsonApiPagination;
use Jurager\Microservice\JsonApi\ResponseError;
use Jurager\Microservice\Manifest\ManifestRepository;
use Jurager\Microservice\Signing\Signer;
use Jurager\Microservice\Tracing\RequestTiming;
use RuntimeException;
use Throwable;

class MicroserviceServiceProvider extends ServiceProvider
{
    /**
     * Register application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/microservice.php', 'microservice');
        $this->normalizeServicesConfig();
        $this->normalizePeersConfig();

        $this->registerCacheStore();

        $this->app->singleton(ManifestRepository::class);
        $this->app->singleton(Signer::class);
        $this->app->singleton(ServiceDiscovery::class);
        $this->app->singleton(CircuitBreaker::class);
        $this->app->scoped(ResponseMemo::class);
        $this->app->scoped(RequestTiming::class);
        $this->app->singleton(ServiceClient::class);
        $this->app->singleton(Connection::class);
        $this->app->singleton(MessageBus::class);
        $this->app->singleton(Listener::class);
        $this->app->singleton(HandlerDiscovery::class);
        $this->app->singleton(ConnectionFactory::class);
        $this->app->when(HealthChecker::class)->needs('$checks')->give(
            static fn (Application $app): array => array_map($app->make(...), (array) config('microservice.health.checks', [])),
        );
    }

    /**
     * Resolve the configured shared cache store when needed.
     */
    private function registerCacheStore(): void
    {
        $this->app->when([
            CacheCheck::class,
            CircuitBreaker::class,
            HealthChecker::class,
            Idempotency::class,
            ManifestRepository::class,
        ])->needs(CacheRepository::class)->give(static function (Application $app): CacheRepository {
            $name = config('microservice.cache.store');
            $store = is_string($name) && $name !== '' ? $app['cache']->store($name) : $app->make(CacheRepository::class);

            self::assertSharedCache($app, is_string($name) && $name !== '' ? $name : (string) config('cache.default'), $store);

            return $store;
        });
    }

    /**
     * Ensure the cache store is shared and supports atomic locks.
     */
    private static function assertSharedCache(Application $app, string $name, CacheRepository $store): void
    {
        $driver = config("cache.stores.$name.driver");
        $local = $app->environment('testing') ? ['file', 'apc', 'null'] : ['array', 'file', 'apc', 'null'];

        if (in_array($driver, $local, true)) {
            throw new RuntimeException("The microservice package needs a cache shared by every instance of the service, but the [$name] store uses the local [$driver] driver. Use redis, memcached, dynamodb or database, or point microservice.cache.store at such a store.");
        }

        if (! $store->getStore() instanceof LockProvider) {
            throw new RuntimeException("The [$name] cache store does not support atomic locks, which the microservice package requires.");
        }
    }

    /**
     * Bootstrap application services.
     */
    public function boot(): void
    {
        $this->configureExceptions();
        JsonApiPagination::registerLinks();
        $this->registerSchedule();

        $this->registerAbout();

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

    /**
     * Normalize manifest services configuration from string to array.
     */
    private function normalizeServicesConfig(): void
    {
        $raw = config('microservice.manifest.services');

        if (is_array($raw)) {
            return;
        }

        $services = [];

        foreach (explode(',', (string) $raw) as $service) {
            $cleaned = trim($service);

            if ($cleaned !== '') {
                $services[] = $cleaned;
            }
        }

        config(['microservice.manifest.services' => $services]);
    }

    /**
     * Normalize peer URLs, skipping empty entries and rejecting invalid values.
     */
    private function normalizePeersConfig(): void
    {
        $services = (array) config('microservice.manifest.services', []);
        $peers = [];

        foreach ((array) config('microservice.peers', []) as $name => $url) {
            $url = is_string($url) ? rtrim(trim($url), '/') : '';

            if ($url === '') {
                continue;
            }

            if (! is_string($name) || $name === '' || ! preg_match('#^https?://[^\s/]+#i', $url)) {
                throw new InvalidArgumentException("Invalid microservice.peers entry [$name]: expected a service name and an http(s) URL.");
            }

            if (in_array($name, $services, true)) {
                throw new InvalidArgumentException("Service [$name] is both a peer and a manifest service; it must be addressed one way only.");
            }

            $peers[$name] = $url;
        }

        config(['microservice.peers' => $peers]);
    }

    /**
     * Register the package configuration status with the about command.
     */
    private function registerAbout(): void
    {
        AboutCommand::add('Microservice', fn (): array => [
            'Service' => (string) config('microservice.name'),
            'Signing' => self::status(fn () => $this->app->make(Signer::class)->assertConfigured()),
            'Cache store' => self::status(fn () => $this->app->make(ManifestRepository::class), (string) (config('microservice.cache.store') ?: config('cache.default'))),
            'Discovery' => config('microservice.discovery.pattern') ?: 'manifests',
            'Message bus' => config('microservice.bus.enabled') ? 'exchange '.config('microservice.bus.exchange') : 'disabled',
        ]);
    }

    /**
     * "OK" (or the given text) when the check passes, the reason it fails otherwise.
     */
    private static function status(callable $check, string $ok = 'OK'): string
    {
        try {
            $check();

            return "<fg=green;options=bold>$ok</>";
        } catch (Throwable $e) {
            return '<fg=red;options=bold>'.$e->getMessage().'</>';
        }
    }

    /**
     * Render exceptions of API requests as JSON:API error documents, unless turned off.
     */
    private function configureExceptions(): void
    {
        if (! config('microservice.render_exceptions', true)) {
            return;
        }

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            $handler->renderable(function (Throwable $e, Request $request): ?JsonResponse {

                if ($e instanceof HttpResponseException) {
                    return null;
                }

                $route = $request->route();
                $isApiRoute = $request->is('api/*') || ($route instanceof Route && in_array('api', $route->gatherMiddleware(), true));

                return $isApiRoute || $request->expectsJson() ? ResponseError::fromException($e) : null;
            });
        });
    }

    /**
     * Register console schedule for manifest synchronization.
     */
    protected function registerSchedule(): void
    {
        $interval = (int) config('microservice.manifest.sync_interval', 5);

        if ($interval <= 0 || $this->knownServices() === []) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($interval): void {
            $schedule->command(SyncCommand::class)->cron("*/{$interval} * * * *");
        });
    }

    /**
     * Get the services whose manifests should be synchronized.
     *
     * @return list<string>
     */
    private function knownServices(): array
    {
        return array_values(array_unique([
            ...(array) config('microservice.manifest.services', []),
            ...array_keys((array) config('microservice.peers', [])),
        ]));
    }
}
