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

    public function handle(ServiceClient $client, ManifestRegistry $registry): int
    {
        $configured = config('microservice.manifest.services', []);

        $services = $this->argument('services') ?: $configured;

        if (empty($services)) {
            $this->components->warn('No services configured. Set manifest.services in config.');

            return self::SUCCESS;
        }

        $failed = [];

        foreach ($services as $service) {
            $reason = null;
            $synced = null;

            $this->components->task("Syncing [$service]", function () use ($service, $client, $registry, &$failed, &$reason, &$synced) {
                try {
                    $response = $client->service($service)->get('/microservice/manifest')->send();

                    if ($response->failed()) {
                        $reason = "HTTP {$response->status()}";
                        $failed[] = $service;

                        return false;
                    }

                    $manifest = $response->json();

                    if (! is_array($manifest) || ! isset($manifest['service'], $manifest['routes'])) {
                        $reason = 'Invalid manifest structure';
                        $failed[] = $service;

                        return false;
                    }

                    $registry->store($manifest);

                    ManifestReceived::dispatch($service, $manifest, count($manifest['routes']));

                    $synced = $manifest;

                    return true;
                } catch (ServiceUnavailableException $e) {
                    $reason = $e->getMessage();
                    $failed[] = $service;

                    return false;
                }
            });

            if ($reason !== null) {
                $this->components->bulletList([$reason]);
            }

            if ($synced !== null) {
                $routes = $synced['routes'];

                $this->components->info(count($routes).' route(s) registered for ['.$synced['service'].'].');

                if (! empty($routes)) {
                    $this->table(['Method', 'URI', 'Name'], array_map(static fn (array $route) => [
                        $route['method'],
                        $route['uri'],
                        $route['name'] ?? '-',
                    ], $routes));
                }
            }
        }

        $synced = array_diff($services, $failed);

        if (! empty($synced) && $this->laravel->routesAreCached()) {
            $this->components->task('Refreshing route cache', fn () => $this->call('route:cache') === 0);
        }

        if (! empty($failed)) {
            $this->components->error('Failed to sync: '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
