<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Command;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Events\RoutesRegistered;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Registry\ManifestRegistry;

class RegisterManifestCommand extends Command
{
    protected $signature = 'microservice:register';

    protected $description = 'Register the service route manifest';

    public function handle(ManifestRegistry $registry, ServiceClient $client): int
    {
        $manifest = $registry->build();

        $this->components->info("Registering manifest for service [{$manifest['service']}]...");

        $gateway = config('microservice.manifest.gateway');

        $serviceName = $manifest['service'];

        if ($gateway === $serviceName || (! $gateway && config("microservice.services.$serviceName"))) {
            $this->components->error("Cannot register manifest: service [$serviceName] appears to be a gateway.");

            return self::FAILURE;
        }

        if ($gateway) {
            try {
                $response = $client->service($gateway)
                    ->post('/microservice/manifest', $manifest)
                    ->send();

                if ($response->failed()) {
                    $this->components->error("Failed to push manifest to gateway [$gateway]: {$response->status()}");

                    return self::FAILURE;
                }
            } catch (ServiceUnavailableException $e) {
                $this->components->error("Gateway [$gateway] is unavailable.");

                if ($e->getPrevious()) {
                    $this->components->bulletList([$e->getPrevious()->getMessage()]);
                }

                return self::FAILURE;
            }
        } else {
            $registry->store($manifest);
        }

        $routes = $manifest['routes'];

        RoutesRegistered::dispatch($serviceName, $routes, $gateway);

        $this->components->info(count($routes).' route(s) registered.');

        $this->table(['Method', 'URI', 'Name'], array_map(static fn (array $route) => [
            $route['method'],
            $route['uri'],
            $route['name'] ?? '-',
        ], $routes));

        return self::SUCCESS;
    }
}
