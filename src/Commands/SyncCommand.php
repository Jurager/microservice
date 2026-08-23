<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Events\ManifestReceived;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;
use Jurager\Microservice\Registry\ManifestRegistry;
use Jurager\Microservice\Registry\RouteRegistry;
use Laravel\Octane\Commands\ReloadCommand;
use Throwable;

#[Signature('microservice:sync {services?* : Service names to sync (defaults to all configured)}')]
#[Description('Pull and store route manifests from configured microservices.')]
class SyncCommand extends Command implements Isolatable
{
    public function isolatableId(): string
    {
        $services = (array) $this->input('services', []);

        sort($services);

        return implode(',', $services);
    }

    public function handle(ServiceClient $client, ManifestRegistry $registry): void
    {
        $services = $this->input('services', []) ?: config('microservice.manifest.services', []);

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

        if ($routesChanged && $this->laravel->routesAreCached()) {
            $this->components->task('Refreshing route cache', fn () => $this->callSilently('route:cache') === 0);
        }

        if ($routesChanged && class_exists(ReloadCommand::class)) {
            $this->components->task('Reloading Octane workers', function () {
                try {
                    return $this->callSilently('octane:reload') === 0;
                } catch (Throwable) {
                    return false;
                }
            });
        }

        foreach ($failed as $service) {
            $stale = $registry->has($service) ? ' (stale manifest retained)' : ' (no manifest stored)';
            $this->components->warn("Could not sync [$service]$stale.");
        }

        if (! empty($failed)) {
            $this->fail('Failed to sync: '.implode(', ', $failed).'.');
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

                if (! is_array($data) || ! isset($data['service'], $data['routes']) || ! is_array($data['routes'])) {
                    $error = 'Invalid manifest structure';

                    return false;
                }

                $manifest = $data;

                return true;
            } catch (ServiceUnavailableException $e) {
                $error = $e->getMessage();

                return false;
            } catch (Throwable $e) {
                $error = get_class($e).': '.$e->getMessage();

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
                implode('|', RouteRegistry::methods($route)) ?: '?',
                $route['uri'] ?? '?',
                $route['name'] ?? '-',
            ], $manifest['routes']));
        }
    }

    private function routesChanged(?array $old, array $new): bool
    {
        $fingerprint = static function (array $routes): string {
            $entries = array_map(
                static fn (array $r) => implode(',', RouteRegistry::methods($r)).'|'.($r['uri'] ?? '').'|'.($r['name'] ?? ''),
                $routes,
            );
            sort($entries);

            return md5(implode("\n", $entries));
        };

        return $fingerprint($old['routes'] ?? []) !== $fingerprint($new['routes']);
    }
}
