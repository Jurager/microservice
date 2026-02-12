<?php

declare(strict_types=1);

namespace Jurager\Microservice;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\ServiceProvider;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Commands\RegisterManifestCommand;
use Jurager\Microservice\Commands\ServiceHealthCommand;
use Jurager\Microservice\Registry\HealthRegistry;
use Jurager\Microservice\Support\HmacSigner;

class MicroserviceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/microservice.php', 'microservice');

        $this->app->singleton(HealthRegistry::class);
        $this->app->singleton(HmacSigner::class);
        $this->app->singleton(ServiceClient::class);
    }

    public function boot(): void
    {
        $this->configureTrustedProxies();

        $this->loadRoutesFrom(__DIR__.'/../routes/microservice.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/microservice.php' => config_path('microservice.php'),
            ], 'microservice-config');

            $this->commands([
                RegisterManifestCommand::class,
                ServiceHealthCommand::class,
            ]);
        }
    }

    protected function configureTrustedProxies(): void
    {
        if (! $gateway = config('microservice.manifest.gateway')) {
            return;
        }

        $hosts = array_values(array_unique(array_filter(array_map(
            static fn ($url) => is_string($url) ? parse_url($url, PHP_URL_HOST) : null,
            (array) config("microservice.services.{$gateway}.base_urls", [])
        ))));

        if ($hosts !== []) {
            TrustProxies::at($hosts);
        }
    }
}
