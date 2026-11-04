<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Client\ServiceDiscovery;
use Jurager\Microservice\Events\ManifestReceived;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Manifest\ManifestRepository;
use Jurager\Microservice\Manifest\ManifestRoute;
use Laravel\Octane\Commands\ReloadCommand;
use Throwable;

#[Signature('microservice:sync {services?* : Service names to sync (defaults to all configured)}')]
#[Description('Pull and store route manifests from configured microservices.')]
class SyncCommand extends Command implements Isolatable
{
    /**
     * Manifest members that change on every sync without changing the manifest.
     */
    private const array VOLATILE = ['timestamp', 'synced_at'];

    /**
     * Run synchronization independently on each gateway host.
     */
    public function isolatableId(): string
    {
        $services = (array) $this->input('services', []);

        sort($services);

        return gethostname().':'.implode(',', $services);
    }

    public function handle(ServiceClient $client, ManifestRepository $registry, ServiceDiscovery $discovery): void
    {
        $services = $this->input('services', []) ?: $discovery->services();

        if (empty($services)) {
            $this->components->warn('No services configured. Set manifest.services or peers in config.');

            return;
        }

        $failed = [];

        foreach ($services as $service) {
            $manifest = $this->pull($service, $client);

            if ($manifest === null) {
                $failed[] = $service;

                continue;
            }

            // Any change counts — a new base URL or timeout with the same routes as well.
            if (self::content($registry->get($service)) !== self::content($manifest)) {
                $registry->store($manifest);
                ManifestReceived::dispatch($service, $manifest, count($manifest['routes']));
            } else {
                $registry->touch($service);
            }

            $this->printRoutes($manifest);
        }

        $this->refreshRoutes($registry->fingerprint());

        foreach ($failed as $service) {
            $stale = $registry->has($service) ? ' (stale manifest retained)' : ' (no manifest stored)';
            $this->components->warn("Could not sync [$service]$stale.");
        }

        if (! empty($failed)) {
            $this->fail('Failed to sync: '.implode(', ', $failed).'.');
        }
    }

    /**
     * Reload local routes when the stored manifests have changed.
     */
    private function refreshRoutes(string $fingerprint): void
    {
        $cached = $this->laravel->routesAreCached();
        $octane = class_exists(ReloadCommand::class);

        if (! $cached && ! $octane) {
            return; // Routes are read from the manifests on every request.
        }

        $loaded = $this->laravel->bootstrapPath('cache/microservice-routes.txt');

        if (is_file($loaded) && file_get_contents($loaded) === $fingerprint) {
            return;
        }

        if ($cached) {
            $this->components->task('Refreshing route cache', fn () => $this->callSilently('route:cache') === 0);
        }

        if ($octane) {
            $this->components->task('Reloading Octane workers', function () {
                try {
                    return $this->callSilently('octane:reload') === 0;
                } catch (Throwable) {
                    return false;
                }
            });
        }

        file_put_contents($loaded, $fingerprint);
    }

    private function pull(string $service, ServiceClient $client): ?array
    {
        $manifest = null;
        $error = null;

        $this->components->task("Syncing [$service]", function () use ($service, $client, &$manifest, &$error) {
            try {
                // Read fresh, even when this process already asked for it.
                $client->forgetMemo($service);

                $data = $client->service($service)->get('/microservice/manifest')->send()->json();

                if (! is_array($data) || ! isset($data['service'], $data['routes']) || ! is_array($data['routes'])) {
                    $error = 'Invalid manifest structure';

                    return false;
                }

                $manifest = $data;

                return true;
            } catch (ServiceRequestException $e) {
                $error = "HTTP {$e->status}";

                return false;
            } catch (Throwable $e) {
                $error = $e instanceof ServiceUnavailableException ? $e->getMessage() : get_class($e).': '.$e->getMessage();

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
        $this->components->info(count($manifest['routes']).' route(s) registered for ['.$manifest['service'].'].');

        if (! empty($manifest['routes'])) {
            $this->table(['Method', 'URI', 'Name'], array_map(static fn (array $route) => [
                implode('|', ManifestRoute::methods($route)) ?: '?',
                $route['uri'] ?? '?',
                $route['name'] ?? '-',
            ], $manifest['routes']));
        }
    }

    /**
     * A manifest without the members that change on every sync.
     */
    private static function content(?array $manifest): ?array
    {
        return $manifest !== null ? array_diff_key($manifest, array_flip(self::VOLATILE)) : null;
    }
}
