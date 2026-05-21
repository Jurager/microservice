<?php

declare(strict_types=1);

namespace Jurager\Microservice;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\MessageBus;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Commands\EmitCommand;
use Jurager\Microservice\Commands\EventsCommand;
use Jurager\Microservice\Commands\SyncManifestsCommand;
use Jurager\Microservice\JsonApi\ResponseError;
use Jurager\Microservice\Support\HmacSigner;
use RabbitEvents\Listener\Dispatcher as RabbitEventsDispatcher;

class MicroserviceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/microservice.php', 'microservice');

        // nuwber's ListenerServiceProvider scans app/Listeners during boot
        // (via app/Listeners discovery). Services that don't have their own
        // listeners would otherwise crash on `composer install` package-discovery
        // and on every artisan command. Create the directory if missing.
        $listenersDir = $this->app->path('Listeners');
        if (! is_dir($listenersDir) && @mkdir($listenersDir, 0o755, true) === false && ! is_dir($listenersDir)) {
            // Ignore — discovery will fail with a clear error; no point throwing here.
        }

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
                EventsCommand::class,
                EmitCommand::class,
            ]);

            $this->registerMessageHandlers();
        }

        $this->registerSchedule();
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
     * Register a listener on the RabbitEvents dispatcher for each MessageHandler
     * declared in config/messages.php. When `rabbitevents:listen` (without args)
     * starts, it picks these up via Dispatcher::getEvents() and binds the
     * matching AMQP queues automatically.
     *
     * Runs in console mode only — mirrors nuwber's own boot behavior and
     * avoids holding listener state in HTTP requests.
     */
    protected function registerMessageHandlers(): void
    {
        if (! class_exists(RabbitEventsDispatcher::class)) {
            return;
        }

        /** @var RabbitEventsDispatcher $dispatcher */
        $dispatcher = $this->app->make(RabbitEventsDispatcher::class);

        foreach ((array) config('messages', []) as $class) {
            if (! is_string($class) || ! is_subclass_of($class, MessageHandler::class)) {
                continue;
            }

            // Closure listeners on RabbitEvents Dispatcher receive ($event, $payload)
            // where $payload is array-wrapped (nuwber's Handler::handle does Arr::wrap).
            // The envelope is the first element of that array.
            $dispatcher->listen($class::type(), function (string $event, array $payload) use ($class): void {
                $envelope = is_array($payload[0] ?? null) ? $payload[0] : [];

                /** @var MessageBus $bus */
                $bus = app(MessageBus::class);

                if (! $bus->verify($envelope)) {
                    Log::warning('MessageBus: rejected envelope with invalid or missing signature', [
                        'type' => $envelope['type'] ?? $event,
                    ]);

                    return;
                }

                $domainPayload = $envelope['payload'] ?? [];

                /** @var MessageHandler $handler */
                $handler = $class::fromMessage(is_array($domainPayload) ? $domainPayload : []);

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
