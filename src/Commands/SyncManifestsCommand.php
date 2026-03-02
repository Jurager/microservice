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
            $this->components->task("Syncing [$service]", function () use ($service, $client, $registry, &$failed) {
                try {
                    $response = $client->service($service)->get('/microservice/manifest')->send();

                    if ($response->failed()) {
                        $failed[] = $service;

                        return false;
                    }

                    $manifest = $response->json();

                    if (! is_array($manifest) || ! isset($manifest['service'], $manifest['routes'])) {
                        $failed[] = $service;

                        return false;
                    }

                    $registry->store($manifest);

                    ManifestReceived::dispatch($service, $manifest, count($manifest['routes']));

                    return true;
                } catch (ServiceUnavailableException) {
                    $failed[] = $service;

                    return false;
                }
            });
        }

        if (! empty($failed)) {
            $this->components->error('Failed to sync: '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
