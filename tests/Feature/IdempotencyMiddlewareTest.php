<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Jurager\Microservice\Http\Middleware\Idempotency;
use Jurager\Microservice\Tests\TestCase;
use Mockery;

class IdempotencyMiddlewareTest extends TestCase
{
    private Connection $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = Mockery::mock(Connection::class);
        Redis::shouldReceive('connection')->andReturn($this->redis);
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
        $this->redis->shouldNotReceive('get');

        $this->getJson('/test/idempotent', ['X-Request-Id' => 'req-1'])
            ->assertOk();
    }

    public function test_non_safe_method_without_request_id_bypasses(): void
    {
        $this->redis->shouldNotReceive('get');

        $this->postJson('/test/idempotent')
            ->assertStatus(201);
    }

    public function test_caches_successful_response(): void
    {
        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn(null);

        $this->redis->shouldReceive('set')
            ->once()
            ->andReturn(true);

        $this->redis->shouldReceive('setex')
            ->once()
            ->withArgs(fn ($key, $ttl) => str_contains($key, 'idempotency:req-1') && $ttl === 60);

        $this->redis->shouldReceive('del')->once();

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => 'req-1'])
            ->assertStatus(201);
    }

    public function test_returns_cached_response_on_duplicate(): void
    {
        $cached = json_encode([
            'status' => 201,
            'headers' => ['content-type' => ['application/json']],
            'content' => '{"created":true}',
        ]);

        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn($cached);

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => 'req-dup'])
            ->assertStatus(201)
            ->assertHeader('X-Idempotency-Cache-Hit', 'true');
    }

    public function test_returns_409_when_lock_held(): void
    {
        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn(null);

        $this->redis->shouldReceive('set')
            ->once()
            ->andReturn(false);

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => 'req-locked'])
            ->assertStatus(409);
    }

    public function test_does_not_cache_failed_responses(): void
    {
        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn(null);

        $this->redis->shouldReceive('set')
            ->once()
            ->andReturn(true);

        $this->redis->shouldNotReceive('setex');

        $this->redis->shouldReceive('del')->once();

        $this->postJson('/test/idempotent-fail', [], ['X-Request-Id' => 'req-fail'])
            ->assertStatus(422);
    }

    public function test_returns_500_for_invalid_cached_data(): void
    {
        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn('not-valid-json{{{');

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => 'req-corrupt'])
            ->assertStatus(500)
            ->assertJson(['message' => 'Invalid cache state']);
    }

    public function test_returns_500_for_cached_data_missing_required_keys(): void
    {
        $cached = json_encode(['some' => 'data']);

        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn($cached);

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => 'req-incomplete'])
            ->assertStatus(500)
            ->assertJson(['message' => 'Invalid cache state']);
    }

    public function test_lock_released_on_exception_in_handler(): void
    {
        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn(null);

        $this->redis->shouldReceive('set')
            ->once()
            ->andReturn(true);

        $this->redis->shouldReceive('del')->once();

        $this->redis->shouldNotReceive('setex');

        try {
            $this->postJson('/test/idempotent-error', [], ['X-Request-Id' => 'req-error']);
        } catch (\Throwable) {
            // Expected — the exception propagates
        }

        // Mockery will verify 'del' was called once (lock released in finally)
    }

    public function test_cached_response_restores_original_headers(): void
    {
        $cached = json_encode([
            'status' => 200,
            'headers' => [
                'content-type' => ['application/json'],
                'x-custom' => ['custom-value'],
            ],
            'content' => '{"ok":true}',
        ]);

        $this->redis->shouldReceive('get')
            ->once()
            ->andReturn($cached);

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => 'req-headers'])
            ->assertStatus(200)
            ->assertHeader('X-Idempotency-Cache-Hit', 'true')
            ->assertHeader('x-custom', 'custom-value');
    }
}
