<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Event;
use Jurager\Microservice\Events\IdempotentRequestDetected;
use Jurager\Microservice\Http\Middleware\Idempotency;
use Jurager\Microservice\Tests\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IdempotencyMiddlewareTest extends TestCase
{
    /** Times each route body actually ran. */
    private static array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$runs = [];
        $this->app['config']->set('microservice.idempotency.ttl', 86400);
    }

    protected function defineRoutes($router): void
    {
        $count = static function (string $route) {
            self::$runs[$route] = (self::$runs[$route] ?? 0) + 1;
        };

        $router->post('/test/idempotent', function () use ($count) {
            $count('created');

            return response()->json(['created' => true, 'run' => self::$runs['created']], 201)->header('X-Custom', 'custom-value');
        })->middleware(Idempotency::class);

        $router->post('/test/other', function () use ($count) {
            $count('other');

            return response()->json(['other' => true], 201);
        })->middleware(Idempotency::class);

        $router->get('/test/idempotent', function () use ($count) {
            $count('read');

            return response()->json(['data' => 'ok']);
        })->middleware(Idempotency::class);

        $router->post('/test/idempotent-fail', function () use ($count) {
            $count('fail');

            return response()->json(['error' => 'bad'], 422);
        })->middleware(Idempotency::class);

        $router->post('/test/idempotent-error', function () {
            throw new \RuntimeException('Something went wrong');
        })->middleware(Idempotency::class);

        // Mirrors what the gateway proxy returns: a body that only exists while it is being streamed out.
        $router->post('/test/idempotent-stream', function () use ($count) {
            $count('stream');

            return new StreamedResponse(function (): void {
                foreach (['{"streamed"', ':true}'] as $chunk) {
                    echo $chunk;

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                }
            }, 200, ['Content-Type' => 'application/json']);
        })->middleware(Idempotency::class);
    }

    private function id(int $n): string
    {
        return sprintf('550e8400-e29b-41d4-a716-%012d', $n);
    }

    /** Cache key the middleware stores a request under. */
    private function keyFor(string $method, string $path, string $requestId): string
    {
        $key = new ReflectionMethod(Idempotency::class, 'key');

        return $key->invoke(app(Idempotency::class), \Illuminate\Http\Request::create($path, $method), $requestId);
    }

    public function test_safe_methods_are_never_deduplicated(): void
    {
        $this->getJson('/test/idempotent', ['X-Request-Id' => $this->id(1)])->assertOk();
        $this->getJson('/test/idempotent', ['X-Request-Id' => $this->id(1)])->assertOk()->assertHeaderMissing('X-Idempotency-Cache-Hit');

        $this->assertSame(2, self::$runs['read']);
    }

    public function test_requests_without_an_id_are_processed_every_time(): void
    {
        $this->postJson('/test/idempotent')->assertStatus(201);
        $this->postJson('/test/idempotent')->assertStatus(201);

        $this->assertSame(2, self::$runs['created']);
    }

    public function test_replays_the_stored_response_for_a_repeated_id(): void
    {
        Event::fake([IdempotentRequestDetected::class]);

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => $this->id(2)])->assertStatus(201)->assertJson(['run' => 1]);

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => $this->id(2)])
            ->assertStatus(201)
            ->assertJson(['run' => 1])
            ->assertHeader('X-Idempotency-Cache-Hit', 'true')
            ->assertHeader('X-Custom', 'custom-value');

        $this->assertSame(1, self::$runs['created']);
        Event::assertDispatched(IdempotentRequestDetected::class, fn (IdempotentRequestDetected $e) => $e->requestId === $this->id(2) && $e->cachedStatusCode === 201);
    }

    public function test_an_id_marked_for_tracing_by_envoy_is_the_same_request(): void
    {
        $this->postJson('/test/idempotent', [], ['X-Request-Id' => $this->id(9)])->assertStatus(201);

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => substr_replace($this->id(9), '9', 14, 1)])
            ->assertStatus(201)
            ->assertJson(['run' => 1])
            ->assertHeader('X-Idempotency-Cache-Hit', 'true');

        $this->assertSame(1, self::$runs['created']);
    }

    public function test_an_id_reused_on_another_endpoint_is_processed(): void
    {
        $this->postJson('/test/idempotent', [], ['X-Request-Id' => $this->id(3)])->assertStatus(201);

        $this->postJson('/test/other', [], ['X-Request-Id' => $this->id(3)])
            ->assertStatus(201)
            ->assertJson(['other' => true])
            ->assertHeaderMissing('X-Idempotency-Cache-Hit');
    }

    public function test_returns_409_while_the_same_request_is_still_running(): void
    {
        $lock = app(Cache::class)->getStore()->lock($this->keyFor('POST', '/test/idempotent', $this->id(4)).':lock', 10);
        $lock->get();

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => $this->id(4)])->assertStatus(409);
        $this->assertArrayNotHasKey('created', self::$runs);

        $lock->release();
    }

    public function test_does_not_store_failed_responses(): void
    {
        $this->postJson('/test/idempotent-fail', [], ['X-Request-Id' => $this->id(5)])->assertStatus(422);
        $this->postJson('/test/idempotent-fail', [], ['X-Request-Id' => $this->id(5)])->assertStatus(422);

        $this->assertSame(2, self::$runs['fail']);
    }

    public function test_releases_the_lock_when_the_handler_throws(): void
    {
        $this->postJson('/test/idempotent-error', [], ['X-Request-Id' => $this->id(6)])->assertStatus(500);

        $this->assertTrue(app(Cache::class)->getStore()->lock($this->keyFor('POST', '/test/idempotent-error', $this->id(6)).':lock', 10)->get());
    }

    public function test_leaves_a_streamed_response_untouched(): void
    {
        $this->postJson('/test/idempotent-stream', [], ['X-Request-Id' => $this->id(7)])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertStreamedContent('{"streamed":true}');

        $this->postJson('/test/idempotent-stream', [], ['X-Request-Id' => $this->id(7)])->assertOk();

        $this->assertSame(2, self::$runs['stream']);
    }

    public function test_does_not_store_a_body_over_the_size_limit(): void
    {
        $this->app['config']->set('microservice.idempotency.max_body_size', 4);

        $this->postJson('/test/idempotent', [], ['X-Request-Id' => $this->id(8)])->assertStatus(201)->assertJson(['created' => true]);
        $this->postJson('/test/idempotent', [], ['X-Request-Id' => $this->id(8)])->assertStatus(201)->assertJson(['run' => 2]);
    }

    public function test_returns_500_for_a_corrupted_cache_entry(): void
    {
        foreach (['not-valid-json{{{', json_encode(['some' => 'data']), json_encode(['status' => 200, 'content' => false])] as $n => $corrupted) {
            app(Cache::class)->put($this->keyFor('POST', '/test/idempotent', $this->id(10 + $n)), $corrupted);

            $this->postJson('/test/idempotent', [], ['X-Request-Id' => $this->id(10 + $n)])
                ->assertStatus(500)
                ->assertJson(['errors' => [['detail' => 'Invalid cache state.']]]);
        }
    }

    public function test_rejects_an_id_that_is_not_a_uuid(): void
    {
        $this->postJson('/test/idempotent', [], ['X-Request-Id' => 'not-a-uuid'])
            ->assertStatus(400)
            ->assertJson(['errors' => [['detail' => 'X-Request-Id must be a valid UUID. Received: not-a-uuid']]]);
    }
}
