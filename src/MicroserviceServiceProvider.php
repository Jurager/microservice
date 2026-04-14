<?php

declare(strict_types=1);

namespace Jurager\Microservice;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Commands\SyncManifestsCommand;
use Jurager\Microservice\JsonApi\ResponseError;
use Jurager\Microservice\Support\HmacSigner;

class MicroserviceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/microservice.php', 'microservice');

        // Normalize manifest.services from comma-separated string to array
        $raw = config('microservice.manifest.services', '');
        if (is_string($raw)) {
            $this->app['config']->set(
                'microservice.manifest.services',
                array_values(array_filter(array_map('trim', explode(',', $raw))))
            );
        }

        $this->app->singleton(HmacSigner::class);
        $this->app->singleton(ServiceClient::class);
    }

    public function boot(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            $handler->renderable(fn (\Throwable $e) => ResponseError::fromException($e));
        });

        $this->configureTrustedProxies();

        $this->loadRoutesFrom(__DIR__.'/../routes/microservice.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/microservice.php' => config_path('microservice.php'),
            ], 'microservice-config');

            $this->commands([
                SyncManifestsCommand::class,
            ]);
        }

        $this->registerSchedule();
    }

    protected function configureTrustedProxies(): void
    {
        if (empty(config('microservice.manifest.services', []))) {
            TrustProxies::at('*');
        }
    }

    protected function registerSchedule(): void
    {
        $interval = (int) config('microservice.manifest.sync_interval', 5);
        $services = config('microservice.manifest.services', []);

        if ($interval <= 0 || empty($services)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($interval) {
            $schedule->command(SyncManifestsCommand::class)->cron("*/$interval * * * *");
        });
    }
}
