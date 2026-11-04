<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Jurager\Microservice\Http\Middleware\LogContext;
use Jurager\Microservice\JsonApi\Concerns\WithEagerIncludes;
use Jurager\Microservice\Tests\TestCase;

class LogContextMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/test/endpoint', fn () => response()->json(Context::all()))->middleware(LogContext::class);

        $router->get('/test/queues', function () {
            ContextProbe::dispatch();

            return response()->json(['ok' => true]);
        })->middleware(LogContext::class);
        $router->get('/test/timed-resource', fn () => TimingResource::collection(collect([['id' => '1']])))->middleware(LogContext::class);
    }

    /** Hit the route with the given headers and read the Context it ran with. */
    private function captureLoggedContext(array $headers = []): array
    {
        return $this->getJson('/test/endpoint', $headers)->assertOk()->json();
    }

    public function test_the_context_travels_with_the_jobs_the_request_queues(): void
    {
        Queue::fake();

        $this->getJson('/test/queues', ['X-Request-Id' => 'a1a2a3a4-b1b2-c1c2-d1d2-d3d4d5d6d7d8'])->assertOk();

        Queue::assertPushed(ContextProbe::class);
        $this->assertSame('a1a2a3a4-b1b2-c1c2-d1d2-d3d4d5d6d7d8', Context::get('request_id'));
    }

    public function test_nothing_of_the_context_outlives_the_request(): void
    {
        $this->getJson('/test/endpoint', ['X-Request-Id' => 'a1a2a3a4-b1b2-c1c2-d1d2-d3d4d5d6d7d8']);

        // What Octane and the queue worker do between requests and jobs.
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstances();

        $this->assertNull(Context::get('request_id'));
    }

    public function test_uses_incoming_request_id(): void
    {
        $context = $this->captureLoggedContext(['X-Request-Id' => 'a1a2a3a4-b1b2-c1c2-d1d2-d3d4d5d6d7d8']);

        $this->assertSame('a1a2a3a4-b1b2-c1c2-d1d2-d3d4d5d6d7d8', $context['request_id']);
    }

    public function test_generates_request_id_when_missing(): void
    {
        $context = $this->captureLoggedContext();

        $this->assertTrue(Str::isUuid($context['request_id']));
    }

    public function test_replaces_a_malformed_request_id(): void
    {
        $injected = "not-a-uuid\n[2026-01-01] production.ERROR: forged line";

        $context = $this->captureLoggedContext(['X-Request-Id' => $injected]);

        $this->assertNotSame($injected, $context['request_id']);
        $this->assertTrue(Str::isUuid($context['request_id']));
    }

    public function test_extracts_trace_id_from_traceparent(): void
    {
        $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';
        $traceparent = "00-$traceId-00f067aa0ba902b7-01";

        $context = $this->captureLoggedContext(['traceparent' => $traceparent]);

        $this->assertSame($traceId, $context['trace_id']);
    }

    public function test_omits_trace_id_without_traceparent(): void
    {
        $context = $this->captureLoggedContext();

        $this->assertArrayNotHasKey('trace_id', $context);
    }

    public function test_omits_a_trace_id_that_is_not_a_w3c_trace_id(): void
    {
        $context = $this->captureLoggedContext(['traceparent' => '00-not-a-trace-id-01']);

        $this->assertArrayNotHasKey('trace_id', $context);
    }

    public function test_document_timings_reach_the_response_and_do_not_leak_into_another_request(): void
    {
        $response = $this->getJson('/test/timed-resource')->assertOk();
        $header = $response->headers->get('Server-Timing');
        $this->assertStringContainsString('jsonapi_load;dur=', $header);
        $this->assertStringContainsString('jsonapi_render;dur=', $header);
        $this->assertStringContainsString('app_total;dur=', $header);

        $next = $this->getJson('/test/endpoint')->assertOk();
        $this->assertStringNotContainsString('jsonapi_load', $next->headers->get('Server-Timing'));
    }

    public function test_response_timings_can_be_disabled(): void
    {
        config(['microservice.tracing.timings' => false]);

        $this->getJson('/test/timed-resource')->assertOk()->assertHeaderMissing('Server-Timing');
    }
}

class TimingResource extends JsonApiResource
{
    use WithEagerIncludes;

    public function toId(Request $request): string
    {
        return $this['id'];
    }

    public function toType(Request $request): string
    {
        return 'timing';
    }

    public function toAttributes(Request $request): array
    {
        return ['ok' => true];
    }
}

class ContextProbe implements ShouldQueue
{
    use Dispatchable;

    public function handle(): void
    {
    }
}
