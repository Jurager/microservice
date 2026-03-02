<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Command;
use Jurager\Microservice\Events\RoutesRegistered;
use Jurager\Microservice\Registry\ManifestRegistry;

class RegisterManifestCommand extends Command
{
    protected $signature = 'microservice:register';

    protected $description = 'Register the service route manifest in the shared Redis';

    public function handle(ManifestRegistry $registry): int
    {
        $manifest = $registry->build();

        $serviceName = $manifest['service'];

        $this->components->info("Registering manifest for service [$serviceName]...");

        $registry->store($manifest);

        $routes = $manifest['routes'];

        RoutesRegistered::dispatch($serviceName, $routes);

        $this->components->info(count($routes).' route(s) registered.');

        $this->table(['Method', 'URI', 'Name'], array_map(static fn (array $route) => [
            $route['method'],
            $route['uri'],
            $route['name'] ?? '-',
        ], $routes));

        return self::SUCCESS;
    }
}
