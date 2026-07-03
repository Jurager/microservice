<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Command;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Events\ManifestReceived;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Registry\ManifestRegistry;

class SyncManifestsCommand extends Command
{
    protected $signature = 'microservice:sync {services?* : Service names to sync (defaults to all configured)}';

    protected $description = 'Pull and store route manifests from configured microservices';

    public function handle(ServiceClient $client, ManifestRegistry $registry): void
    {
        $services = $this->argument('services') ?: config('microservice.manifest.services', []);

        if (empty($services)) {
            $this->components->warn('No services configured. Set manifest.services in config.');

            return;
        }

        $failed = [];
        $routesChanged = false;

        foreach ($services as $service) {
            $manifest = $this->pull($service, $client);

            if ($manifest === null) {
                $failed[] = $service;

                continue;
            }

            $old = $registry->get($service);

            if ($this->routesChanged($old, $manifest)) {
                $routesChanged = true;
                $registry->store($manifest);
                ManifestReceived::dispatch($service, $manifest, count($manifest['routes']));
            } else {
                $registry->touch($service);
            }

            $this->printRoutes($manifest);
        }

        $succeeded = array_diff($services, $failed);

        if (! empty($succeeded) && $this->laravel->routesAreCached()) {
            $this->components->task('Refreshing route cache', fn () => $this->call('route:cache') === 0);
        }

        if ($routesChanged && class_exists(\Laravel\Octane\Commands\ReloadCommand::class)) {

            $this->components->task('Reloading Octane workers', function () {
                try {
                    return $this->call('octane:reload') === 0;
                } catch (\Throwable) {
                    return false;
                }
            });
        }

        foreach ($failed as $service) {
            $stale = $registry->has($service) ? ' (stale manifest retained)' : ' (no manifest stored)';
            $this->components->warn("Could not sync [$service]$stale.");
        }
    }

    private function pull(string $service, ServiceClient $client): ?array
    {
        $manifest = null;
        $error = null;

        $this->components->task("Syncing [$service]", function () use ($service, $client, &$manifest, &$error) {

            try {
                $response = $client->service($service)->get('/microservice/manifest')->send();

                if ($response->failed()) {
                    $error = "HTTP {$response->status()}";

                    return false;
                }

                $data = $response->json();

                if (! is_array($data) || ! isset($data['service'], $data['routes'])) {
                    $error = 'Invalid manifest structure';

                    return false;
                }

                $manifest = $data;

                return true;

            } catch (ServiceUnavailableException $e) {
                $error = $e->getMessage();

                return false;
            }
        });

        if ($error !== null) {
            $this->components->bulletList([$error]);
        }

        return $manifest;
    }

    private function printRoutes(array $manifest): void
    {
        $routes = $manifest['routes'];

        $this->components->info(count($routes).' route(s) registered for ['.$manifest['service'].'].');

        if (! empty($routes)) {
            $this->table(['Method', 'URI', 'Name'], array_map(static fn (array $route) => [
                $route['method'],
                $route['uri'],
                $route['name'] ?? '-',
            ], $routes));
        }
    }

    private function routesChanged(?array $old, array $new): bool
    {
        $fingerprint = static function (array $routes): string {
            $entries = array_map(static fn ($r) => $r['method'].'|'.$r['uri'], $routes);
            sort($entries);

            return md5(implode(',', $entries));
        };

        return $fingerprint($old['routes'] ?? []) !== $fingerprint($new['routes']);
    }
}
