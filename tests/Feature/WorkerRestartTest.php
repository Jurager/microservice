<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jurager\Microservice\Bus\Connection;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Signing\Signer;
use Jurager\Microservice\Tests\TestCase;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

class WorkerRestartTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('microservice.bus.dead_letter.enabled', false);
    }

    public function test_the_worker_stops_on_queue_restart(): void
    {
        $this->app->instance(HandlerDiscovery::class, new class extends HandlerDiscovery
        {
            public function discover(): array
            {
                return [RestartProbeHandler::class];
            }
        });

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('getConnection')->andReturn(Mockery::mock(AbstractConnection::class, ['channel' => $channel]));
        $channel->shouldReceive('close');
        $channel->shouldReceive('basic_qos', 'queue_declare', 'queue_bind', 'basic_consume');
        $channel->shouldReceive('is_consuming')->andReturn(true);

        // A deploy runs `php artisan queue:restart` while the worker waits for messages.
        $channel->shouldReceive('wait')->once()->andReturnUsing(function (): void {
            // What `php artisan queue:restart` does.
            Cache::forever('illuminate:queue:restart', now()->getTimestamp());

            throw new AMQPTimeoutException();
        });

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('channel')->andReturn($channel);
        $connection->shouldReceive('close')->zeroOrMoreTimes();
        $this->app->instance(Connection::class, $connection);

        Cache::forget('illuminate:queue:restart');

        $this->artisan('microservice:listen', ['--memory' => 0, '--rescan' => 0])
            ->expectsOutputToContain('Restart signal received, stopping.')
            ->assertSuccessful();
    }

    public function test_each_message_starts_with_fresh_query_duration_tracking(): void
    {
        $this->app->instance(HandlerDiscovery::class, new class extends HandlerDiscovery
        {
            public function discover(): array
            {
                return [QueryingProbeHandler::class];
            }
        });

        $signer = $this->app->make(Signer::class);
        $envelope = ['id' => (string) Str::uuid(), 'type' => 'test.query', 'service' => 'test-service', 'occurred_at' => now()->toIso8601String(), 'request_id' => null, 'payload' => []];
        $envelope['signature'] = $signer->signRaw(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $envelope['certificate'] = $signer->certificate();

        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getBody')->andReturn(json_encode($envelope));
        $message->shouldReceive('getRoutingKey')->andReturn('test.query');
        $message->shouldReceive('ack')->once();

        $consume = null;
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('getConnection')->andReturn(Mockery::mock(AbstractConnection::class, ['channel' => $channel]));
        $channel->shouldReceive('close');
        $channel->shouldReceive('basic_qos', 'queue_declare', 'queue_bind');
        $channel->shouldReceive('basic_consume')->andReturnUsing(function (...$arguments) use (&$consume): void {
            $consume = $arguments['callback'] ?? $arguments[6];
        });
        $channel->shouldReceive('is_consuming')->andReturn(true);
        $channel->shouldReceive('wait')->once()->andReturnUsing(function () use (&$consume, $message): void {
            $consume($message);
            Cache::forever('illuminate:queue:restart', now()->getTimestamp());
        });

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('channel')->andReturn($channel);
        $connection->shouldReceive('close')->zeroOrMoreTimes();
        $this->app->instance(Connection::class, $connection);

        Cache::forget('illuminate:queue:restart');

        $this->artisan('microservice:listen', ['--memory' => 0, '--rescan' => 0])->assertSuccessful();

        $this->assertGreaterThan(0, QueryingProbeHandler::$duration, 'The handler ran a query.');
        $this->assertSame(0.0, (float) DB::connection()->totalQueryDuration(), 'The next message starts from zero, as a queued job does.');
    }
}

class QueryingProbeHandler implements MessageHandler
{
    public static float $duration = 0;

    public static function type(): string
    {
        return 'test.query';
    }

    public static function from(array $payload, string $type = ''): static
    {
        return new static;
    }

    public function handle(): void
    {
        DB::select('select 1');

        self::$duration = (float) DB::connection()->totalQueryDuration();
    }
}

class RestartProbeHandler implements MessageHandler
{
    public static function type(): string
    {
        return 'test.restart';
    }

    public static function from(array $payload, string $type = ''): static
    {
        return new static;
    }

    public function handle(): void
    {
    }
}
