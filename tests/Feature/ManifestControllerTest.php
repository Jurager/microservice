<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Redis\Connections\Connection;
use Jurager\Microservice\Http\Middleware\TrustService;
use Jurager\Microservice\Registry\ManifestRegistry;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class ManifestControllerTest extends TestCase
{
    private function validPayload(): array
    {
        return [
            'service' => 'pim',
            'routes' => [
                ['method' => 'GET', 'uri' => '/api/products'],
                ['method' => 'POST', 'uri' => '/api/products'],
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function test_stores_valid_manifest(): void
    {
        $redis = Mockery::mock(Connection::class);
        $redis->shouldReceive('setex')->once();
        $redis->shouldReceive('sadd')->once();

        $registry = Mockery::mock(ManifestRegistry::class)->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('redis')->andReturn($redis);

        $this->app->instance(ManifestRegistry::class, $registry);

        $this->withoutMiddleware(TrustService::class)
            ->postJson('/microservice/manifest', $this->validPayload())
            ->assertOk()
            ->assertJson(['status' => 'registered']);
    }

    public function test_validates_required_fields(): void
    {
        $this->withoutMiddleware(TrustService::class)
            ->postJson('/microservice/manifest', ['routes' => []])
            ->assertStatus(422);
    }

    public function test_validates_route_structure(): void
    {
        $this->withoutMiddleware(TrustService::class)
            ->postJson('/microservice/manifest', [
                'service' => 'pim',
                'routes' => [['invalid' => true]],
                'timestamp' => now()->toIso8601String(),
            ])
            ->assertStatus(422);
    }
}
