<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Cache\Repository as Cache;
use Jurager\Microservice\Http\Middleware\Idempotency;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class IdempotencyMiddlewareTest extends TestCase
{
    private Cache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('microservice.idempotency.ttl', 86400);

        $this->cache = Mockery::mock(Cache::class);
        $this->app->instance(Cache::class, $this->cache);
    }

    protected function defineRoutes($router): void
    {
        $router->post('/test/idempotent', fn () => response()->json(['created' => true], 201))
            ->middleware(Idempotency::class);

        $router->get('/test/idempotent', fn () => response()->json(['data' => 'ok']))
            ->middleware(Idempotency::class);

        $router->post('/test/idempotent-fail', fn () => response()->json(['error' => 'bad'], 422))
            ->middleware(Idempotency::class);

        $router->post('/test/idempotent-error', function () {
            throw new \RuntimeException('Something went wrong');
        })->middleware(Idempotency::class);
    }

    public function test_safe_methods_bypass_idempotency(): void
    {
        $this->cache->shouldNotReceive('get');

        $this->getJson('/test/idempotent', ['X-Request-Id' => '550e8400-e29b-41d4-a716-446655440001'])
            ->assertOk();
    }

    public function test_non_safe_method_without_request_id_bypasses(): void
    {
        $this->cache->shouldNotReceive('get');

        $this->postJson('/test/idempotent')
            ->assertStatus(201);
    }

    public function test_caches_successful_response(): void
    {
        $requestId = '550e8400-e29b-41d4-a716-446655440002';

        $this->cache->shouldReceive('get')
            ->once()
            ->andReturn(null);

        $this->cache->shouldReceive('add')
            ->once()
            ->andReturn(true);

        $this->cache->shouldReceive('put')
            ->once()
            ->withArgs(fn ($key, $data, $ttl) => str_contains($key, "idempotency:$requestId") && $ttl === 86400);

        $this->cache->shouldReceive('forget')->once();

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => $requestId])
            ->assertStatus(201);
    }

    public function test_returns_cached_response_on_duplicate(): void
    {
        $requestId = '550e8400-e29b-41d4-a716-446655440003';

        $cached = json_encode([
            'status' => 201,
            'headers' => ['content-type' => ['application/json']],
            'content' => '{"created":true}',
        ]);

        $this->cache->shouldReceive('get')
            ->once()
            ->andReturn($cached);

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => $requestId])
            ->assertStatus(201)
            ->assertHeader('X-Idempotency-Cache-Hit', 'true');
    }

    public function test_returns_409_when_lock_held(): void
    {
        $this->cache->shouldReceive('get')
            ->twice()
            ->andReturn(null);

        $this->cache->shouldReceive('add')
            ->once()
            ->andReturn(false);

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => '550e8400-e29b-41d4-a716-446655440004'])
            ->assertStatus(409);
    }

    public function test_does_not_cache_failed_responses(): void
    {
        $requestId = '550e8400-e29b-41d4-a716-446655440005';

        $this->cache->shouldReceive('get')
            ->once()
            ->andReturn(null);

        $this->cache->shouldReceive('add')
            ->once()
            ->andReturn(true);

        $this->cache->shouldNotReceive('put');

        $this->cache->shouldReceive('forget')->once();

        $this->postJson('/test/idempotent-fail', [], ['X-Request-Id' => $requestId])
            ->assertStatus(422);
    }

    public function test_returns_500_for_invalid_cached_data(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->andReturn('not-valid-json{{{');

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => '550e8400-e29b-41d4-a716-446655440006'])
            ->assertStatus(500)
            ->assertJson(['errors' => [['detail' => 'Invalid cache state.']]]);
    }

    public function test_returns_500_for_cached_data_missing_required_keys(): void
    {
        $cached = json_encode(['some' => 'data']);

        $this->cache->shouldReceive('get')
            ->once()
            ->andReturn($cached);

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => '550e8400-e29b-41d4-a716-446655440007'])
            ->assertStatus(500)
            ->assertJson(['errors' => [['detail' => 'Invalid cache state.']]]);
    }

    public function test_lock_released_on_exception_in_handler(): void
    {
        $this->cache->shouldReceive('get')
            ->once()
            ->andReturn(null);

        $this->cache->shouldReceive('add')
            ->once()
            ->andReturn(true);

        $this->cache->shouldReceive('forget')->once();

        $this->cache->shouldNotReceive('put');

        try {
            $this->postJson('/test/idempotent-error', [], ['X-Request-Id' => '550e8400-e29b-41d4-a716-446655440008']);
        } catch (\Throwable) {
            // Expected — the exception propagates
        }

        // Mockery will verify 'forget' was called once (lock released in finally)
    }

    public function test_cached_response_restores_original_headers(): void
    {
        $requestId = '550e8400-e29b-41d4-a716-446655440009';

        $cached = json_encode([
            'status' => 200,
            'headers' => [
                'content-type' => ['application/json'],
                'x-custom' => ['custom-value'],
            ],
            'content' => '{"ok":true}',
        ]);

        $this->cache->shouldReceive('get')
            ->once()
            ->andReturn($cached);

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => $requestId])
            ->assertStatus(200)
            ->assertHeader('X-Idempotency-Cache-Hit', 'true')
            ->assertHeader('x-custom', 'custom-value');
    }

    public function test_rejects_invalid_uuid(): void
    {
        $this->cache->shouldNotReceive('get');

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => 'not-a-uuid'])
            ->assertStatus(400)
            ->assertJson(['errors' => [['detail' => 'X-Request-Id must be a valid UUID. Received: not-a-uuid']]]);
    }
}
