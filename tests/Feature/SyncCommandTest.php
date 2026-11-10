<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Jurager\Microservice\Commands\SyncCommand;
use Jurager\Microservice\Manifest\ManifestRepository;
use Jurager\Microservice\MicroserviceServiceProvider;
use Jurager\Microservice\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

class SyncCommandTest extends TestCase
{
    private int $routeCacheRuns = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['microservice.manifest.services' => ['pim'], 'microservice.discovery.pattern' => 'http://{service}.svc']);

        @unlink($this->loadedFingerprint());
    }

    protected function tearDown(): void
    {
        @unlink($this->loadedFingerprint());

        parent::tearDown();
    }

    private function loadedFingerprint(): string
    {
        return $this->app->bootstrapPath('cache/microservice-routes.txt');
    }

    private function manifest(string $baseUrl = 'http://pim.internal', int $timeout = 30): array
    {
        return [
            'service' => 'pim',
            'base_url' => $baseUrl,
            'timeout' => $timeout,
            'routes' => [['methods' => ['GET'], 'uri' => '/v1/products', 'name' => 'products.index']],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /** Pretend the routes of this instance are cached, and count how often they are rebuilt. */
    private function cacheRoutes(): void
    {
        $this->app->instance('routes.cached', true);

        // The closure is bound to the command it defines, so the counter is captured by reference.
        $runs = &$this->routeCacheRuns;
        Artisan::command('route:cache', function () use (&$runs) {
            $runs++;
        });
    }

    public function test_a_changed_base_url_is_stored_even_when_the_routes_are_the_same(): void
    {
        $repository = $this->app->make(ManifestRepository::class);
        $repository->store($this->manifest('http://pim.old'));

        Http::fake(['*' => Http::response($this->manifest('http://pim.new', 15))]);

        $this->artisan('microservice:sync')->assertSuccessful();

        $this->assertSame('http://pim.new', $repository->get('pim')['base_url']);
        $this->assertSame(15, $repository->get('pim')['timeout']);
    }

    public function test_optimizing_a_gateway_syncs_the_manifests_and_rebuilds_its_cached_routes(): void
    {
        $this->app->register(MicroserviceServiceProvider::class, force: true);
        $this->cacheRoutes();

        Http::fake(['*' => Http::response($this->manifest())]);

        $this->artisan('optimize', ['--except' => 'config,events,routes,views'])->assertSuccessful();

        $this->assertSame($this->manifest()['routes'], $this->app->make(ManifestRepository::class)->get('pim')['routes'] ?? null);
        $this->assertSame(1, $this->routeCacheRuns);
    }

    public function test_an_instance_refreshes_its_routes_when_another_one_stored_the_change(): void
    {
        $this->cacheRoutes();

        // Another gateway instance has stored the new manifest already.
        $this->app->make(ManifestRepository::class)->store($this->manifest());
        Http::fake(['*' => Http::response($this->manifest())]);

        $this->artisan('microservice:sync')->assertSuccessful();
        $this->assertSame(1, $this->routeCacheRuns);

        // Nothing moved since this instance rebuilt them.
        $this->artisan('microservice:sync')->assertSuccessful();
        $this->assertSame(1, $this->routeCacheRuns);
    }

    public function test_routes_are_rebuilt_again_once_the_routes_change(): void
    {
        $this->cacheRoutes();

        Http::fake(['*' => Http::sequence([
            Http::response($this->manifest()),
            Http::response([...$this->manifest(), 'routes' => [['methods' => ['GET'], 'uri' => '/v1/categories']]]),
        ])]);

        $this->artisan('microservice:sync')->assertSuccessful();
        $this->artisan('microservice:sync')->assertSuccessful();

        $this->assertSame(2, $this->routeCacheRuns);
    }

    public function test_runs_are_isolated_per_host(): void
    {
        $command = $this->app->make(SyncCommand::class);
        $command->setInput(new ArrayInput(['services' => ['pim']], $command->getDefinition()));

        $this->assertSame(gethostname().':pim', $command->isolatableId());
    }
}
