<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Jurager\Microservice\Commands\SyncCommand;
use Jurager\Microservice\Gateway\Gateway;
use Jurager\Microservice\Manifest\ManifestRepository;
use Jurager\Microservice\MicroserviceServiceProvider;
use Jurager\Microservice\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

class SyncCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['microservice.manifest.services' => ['pim'], 'microservice.discovery.pattern' => 'http://{service}.svc']);

        @unlink($this->app->getCachedRoutesPath());
    }

    protected function tearDown(): void
    {
        @unlink($this->app->getCachedRoutesPath());

        parent::tearDown();
    }

    private function manifest(string $baseUrl = 'http://pim.internal', int $timeout = 30, array $routes = []): array
    {
        return [
            'service' => 'pim',
            'base_url' => $baseUrl,
            'timeout' => $timeout,
            'routes' => $routes ?: [['methods' => ['GET'], 'uri' => '/v1/products', 'name' => 'products.index']],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /** Leave a route cache file, as route:cache does. */
    private function cacheRoutes(): void
    {
        file_put_contents($this->app->getCachedRoutesPath(), '<?php');
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

    public function test_a_route_a_service_adds_is_served_by_the_gateway_once_synced(): void
    {
        $this->app->make(ManifestRepository::class)->store($this->manifest());

        Gateway::routes();

        Http::fake([
            'pim.svc/microservice/manifest' => Http::response($this->manifest(routes: [
                ['methods' => ['GET'], 'uri' => '/v1/products', 'name' => 'products.index'],
                ['methods' => ['GET'], 'uri' => '/v1/categories', 'name' => 'categories.index'],
            ])),
            'pim.svc/v1/categories' => Http::response(['data' => []]),
        ]);

        $this->getJson('/pim/v1/categories')->assertNotFound();

        $this->artisan('microservice:sync')->assertSuccessful();

        $this->getJson('/pim/v1/categories')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_optimizing_a_gateway_syncs_the_manifests_and_leaves_its_routes_uncached(): void
    {
        $this->app->register(MicroserviceServiceProvider::class, force: true);

        Gateway::routes();

        $path = $this->app->getCachedRoutesPath();
        Artisan::command('route:cache', function () use ($path) {
            file_put_contents($path, '<?php');
        });

        Http::fake(['*' => Http::response($this->manifest())]);

        $this->artisan('optimize', ['--except' => 'config,events,views'])->assertSuccessful();

        $this->assertSame($this->manifest()['routes'], $this->app->make(ManifestRepository::class)->get('pim')['routes'] ?? null);
        $this->assertFileDoesNotExist($path);
    }

    public function test_gateway_routes_loaded_from_the_route_cache_are_cleared_as_well(): void
    {
        $this->app->make(ManifestRepository::class)->store($this->manifest());

        Gateway::routes();

        // As in a process that loaded them from the route cache.
        $this->app->forgetInstance(Gateway::class);
        $this->cacheRoutes();

        Http::fake(['*' => Http::response($this->manifest())]);

        $this->artisan('microservice:sync')->assertSuccessful();

        $this->assertFileDoesNotExist($this->app->getCachedRoutesPath());
    }

    public function test_a_service_without_gateway_routes_keeps_its_route_cache(): void
    {
        $this->cacheRoutes();

        Http::fake(['*' => Http::response($this->manifest())]);

        $this->artisan('microservice:sync')->assertSuccessful();

        $this->assertFileExists($this->app->getCachedRoutesPath());
    }

    public function test_runs_are_isolated_per_host(): void
    {
        $command = $this->app->make(SyncCommand::class);
        $command->setInput(new ArrayInput(['services' => ['pim']], $command->getDefinition()));

        $this->assertSame(gethostname().':pim', $command->isolatableId());
    }
}
