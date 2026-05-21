<?php

declare(strict_types=1);

namespace Jurager\Microservice;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\MessageBus;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Commands\EmitCommand;
use Jurager\Microservice\Commands\EventsCommand;
use Jurager\Microservice\Commands\ListenCommand;
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
        $this->app->singleton(MessageBus::class);
    }

    private function validateSecret(): void
    {
        if (config('microservice.debug', false)) {
            return;
        }

        $secret = config('microservice.secret');

        if (empty($secret)) {
            throw new \RuntimeException(
                'Invalid SERVICE_SECRET value. HMAC signing requires a non-empty shared secret'
            );
        }
    }

    public function boot(): void
    {
        $this->validateSecret();

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
                ListenCommand::class,
                EventsCommand::class,
                EmitCommand::class,
            ]);
        }

        $this->registerSchedule();
        $this->registerMessageHandlers();
    }

    protected function configureTrustedProxies(): void
    {
        if (empty(config('microservice.manifest.services', [])) && config('microservice.trust_all_proxies', true)) {
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

    /**
     * Auto-register Laravel event listeners for each MessageHandler declared
     * in config/messages.php. The rabbitevents consumer dispatches the local
     * event on AMQP receive — the closure unwraps the envelope, constructs the
     * handler, and routes it through Laravel's queue when it implements
     * ShouldQueue (so retries, failed_jobs and backoff apply) or invokes it
     * synchronously otherwise.
     */
    protected function registerMessageHandlers(): void
    {
        foreach ((array) config('messages', []) as $class) {
            if (! is_string($class) || ! is_subclass_of($class, MessageHandler::class)) {
                continue;
            }

            Event::listen($class::type(), function (array $envelope) use ($class): void {
                /** @var MessageBus $bus */
                $bus = app(MessageBus::class);

                if (! $bus->verify($envelope)) {
                    Log::warning('MessageBus: rejected envelope with invalid or missing signature', [
                        'type' => $envelope['type'] ?? $class::type(),
                    ]);

                    return;
                }

                $payload = $envelope['payload'] ?? [];

                /** @var MessageHandler $handler */
                $handler = $class::fromMessage(is_array($payload) ? $payload : []);

                if ($handler instanceof ShouldQueue) {
                    dispatch($handler);
                    return;
                }

                if (method_exists($handler, 'handle')) {
                    $handler->handle();
                }
            });
        }
    }
}
