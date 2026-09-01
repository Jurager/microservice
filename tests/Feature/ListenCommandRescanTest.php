<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Bus\Connection;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Tests\TestCase;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;

/**
 * A handler's type() list can change while the worker is already running —
 * DispatchNotificationTrigger reflects whichever NotificationTrigger rows
 * are active right now, and that set grows the moment someone activates a
 * new trigger. These tests prove the listener notices that without being
 * restarted.
 */
class ListenCommandRescanTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Keeps the mock surface to what these tests actually assert on.
        $app['config']->set('microservice.bus.dead_letter.enabled', false);
    }

    public function test_picks_up_a_newly_active_event_type_without_restarting(): void
    {
        DynamicFakeHandler::$activeTypes = [];

        $this->app->instance(HandlerDiscovery::class, new class extends HandlerDiscovery
        {
            public function discover(): array
            {
                return [DynamicFakeHandler::class];
            }
        });

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_qos')->once();
        $channel->shouldReceive('is_consuming')->andReturn(false);

        // Stands in for a message arriving mid-wait: the trigger becomes
        // active while the worker is already blocked in the wait window,
        // then the window elapses without a message to explicitly cross the
        // --rescan threshold below.
        $channel->shouldReceive('wait')->once()->andReturnUsing(function (): void {
            DynamicFakeHandler::$activeTypes = ['test.dynamic'];
            usleep(1_100_000);

            throw new AMQPTimeoutException();
        });

        $channel->shouldReceive('queue_declare')
            ->once()
            ->withArgs(fn (string $queue): bool => $queue === 'test-service.test.dynamic');
        $channel->shouldReceive('queue_bind')
            ->once()
            ->withArgs(fn (string $queue, string $exchange, string $type): bool => $queue === 'test-service.test.dynamic'
                && $exchange === 'events'
                && $type === 'test.dynamic');
        $channel->shouldReceive('basic_consume')
            ->once()
            ->withArgs(fn (string $queue): bool => $queue === 'test-service.test.dynamic');

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('channel')->andReturn($channel);
        $connection->shouldReceive('exchange')->andReturn('events');
        $connection->shouldReceive('close')->zeroOrMoreTimes();
        $this->app->instance(Connection::class, $connection);

        // --memory=1 guarantees the loop stops after exactly one tick — real
        // process usage is always above 1MB — so the run is deterministic
        // without needing signals or a fake clock.
        $this->artisan('microservice:listen', ['--rescan' => 1, '--memory' => 1])
            ->expectsOutputToContain('No event types active yet')
            ->expectsOutputToContain('Now also listening for: test.dynamic')
            ->assertSuccessful();
    }

    public function test_drops_a_deactivated_event_type_without_restarting(): void
    {
        DynamicFakeHandler::$activeTypes = ['test.dynamic'];

        $this->app->instance(HandlerDiscovery::class, new class extends HandlerDiscovery
        {
            public function discover(): array
            {
                return [DynamicFakeHandler::class];
            }
        });

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_qos')->once();
        $channel->shouldReceive('is_consuming')->andReturn(false);

        $channel->shouldReceive('queue_declare')
            ->once()
            ->withArgs(fn (string $queue): bool => $queue === 'test-service.test.dynamic');
        $channel->shouldReceive('queue_bind')
            ->once()
            ->withArgs(fn (string $queue, string $exchange, string $type): bool => $queue === 'test-service.test.dynamic'
                && $exchange === 'events'
                && $type === 'test.dynamic');
        $channel->shouldReceive('basic_consume')
            ->once()
            ->withArgs(fn (string $queue): bool => $queue === 'test-service.test.dynamic');

        // Stands in for the last active trigger for this type being
        // deactivated while the worker is already blocked in the wait
        // window, then the window elapses without a message to explicitly
        // cross the --rescan threshold below.
        $channel->shouldReceive('wait')->once()->andReturnUsing(function (): void {
            DynamicFakeHandler::$activeTypes = [];
            usleep(1_100_000);

            throw new AMQPTimeoutException();
        });

        // Cancels the local consumer only — never unbinds or deletes the queue.
        $channel->shouldReceive('basic_cancel')
            ->once()
            ->withArgs(fn (string $consumerTag): bool => $consumerTag === 'test-service-test.dynamic');
        $channel->shouldNotReceive('queue_unbind');
        $channel->shouldNotReceive('queue_delete');

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('channel')->andReturn($channel);
        $connection->shouldReceive('exchange')->andReturn('events');
        $connection->shouldReceive('close')->zeroOrMoreTimes();
        $this->app->instance(Connection::class, $connection);

        $this->artisan('microservice:listen', ['--rescan' => 1, '--memory' => 1])
            ->expectsOutputToContain('Listening for events: test.dynamic')
            ->expectsOutputToContain('No longer listening for: test.dynamic')
            ->assertSuccessful();
    }

    public function test_keeps_running_with_no_active_types_when_rescan_is_enabled(): void
    {
        DynamicFakeHandler::$activeTypes = [];

        $this->app->instance(HandlerDiscovery::class, new class extends HandlerDiscovery
        {
            public function discover(): array
            {
                return [DynamicFakeHandler::class];
            }
        });

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_qos')->once();
        $channel->shouldReceive('is_consuming')->andReturn(false);
        $channel->shouldReceive('wait')->once()->andThrow(new AMQPTimeoutException());
        $channel->shouldNotReceive('queue_declare');
        $channel->shouldNotReceive('basic_consume');

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('channel')->andReturn($channel);
        $connection->shouldReceive('exchange')->andReturn('events');
        $connection->shouldReceive('close')->zeroOrMoreTimes();
        $this->app->instance(Connection::class, $connection);

        $this->artisan('microservice:listen', ['--rescan' => 30, '--memory' => 1])
            ->expectsOutputToContain('No event types active yet')
            ->assertSuccessful();
    }

    public function test_warns_and_exits_when_no_types_are_active_and_rescan_is_disabled(): void
    {
        DynamicFakeHandler::$activeTypes = [];

        $this->app->instance(HandlerDiscovery::class, new class extends HandlerDiscovery
        {
            public function discover(): array
            {
                return [DynamicFakeHandler::class];
            }
        });

        $connection = Mockery::mock(Connection::class);
        $connection->shouldNotReceive('channel');
        $this->app->instance(Connection::class, $connection);

        $this->artisan('microservice:listen', ['--rescan' => 0])
            ->expectsOutputToContain('--rescan is disabled')
            ->assertSuccessful();
    }
}

class DynamicFakeHandler implements MessageHandler
{
    /** @var list<string> */
    public static array $activeTypes = [];

    public function __construct(public readonly array $payload)
    {
    }

    public static function type(): array
    {
        return self::$activeTypes;
    }

    public static function from(array $payload, string $type = ''): static
    {
        return new static($payload);
    }

    public function handle(): void
    {
    }
}
