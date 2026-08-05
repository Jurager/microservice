<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jurager\Microservice\Http\Middleware\LogContext;
use Jurager\Microservice\Tests\TestCase;

class LogContextMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/test/endpoint', function () {
            Log::info('inside the request');

            return response()->json(['ok' => true]);
        })->middleware(LogContext::class);
    }

    /** Hit the route with the given headers and capture the context of the line it logs. */
    private function captureLoggedContext(array $headers = []): array
    {
        $context = [];

        Log::listen(function (MessageLogged $event) use (&$context): void {
            $context = $event->context;
        });

        $this->getJson('/test/endpoint', $headers)->assertOk();

        return $context;
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
}
