<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Jurager\Microservice\Bus\Connection;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Signing\Signer;
use Jurager\Microservice\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

/**
 * Deliver each message to every handler of its type in the service.
 */
class WorkerFanOutTest extends TestCase
{
    private ?Closure $consumer = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('microservice.bus.dead_letter.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        FanOutProbe::$received = [];
        JoiningFanOutHandler::$activeTypes = [];

        Cache::forget('illuminate:queue:restart');
    }

    public function test_every_handler_of_a_type_receives_the_message_from_one_queue(): void
    {
        $message = $this->message('test.shared', ['id' => 7]);
        $message->shouldReceive('ack')->once();
        $message->shouldNotReceive('nack');

        $channel = $this->channel();
        $channel->shouldReceive('wait')->once()->andReturnUsing(function () use ($message): void {
            ($this->consumer)($message);
            $this->restart();
        });

        $this->listen([FirstFanOutHandler::class, SecondFanOutHandler::class], $channel)
            ->expectsOutputToContain('sync → '.FirstFanOutHandler::class)
            ->expectsOutputToContain('sync → '.SecondFanOutHandler::class)
            ->assertSuccessful();

        $this->assertSame([
            [FirstFanOutHandler::class, ['id' => 7]],
            [SecondFanOutHandler::class, ['id' => 7]],
        ], FanOutProbe::$received);
    }

    public function test_a_failing_handler_does_not_keep_the_message_from_the_others(): void
    {
        $message = $this->message('test.shared', ['id' => 7]);
        $message->shouldReceive('nack')->once();
        $message->shouldNotReceive('ack');

        $channel = $this->channel();
        $channel->shouldReceive('wait')->once()->andReturnUsing(function () use ($message): void {
            ($this->consumer)($message);
            $this->restart();
        });

        $this->listen([FailingFanOutHandler::class, SecondFanOutHandler::class], $channel)
            ->expectsOutputToContain(FailingFanOutHandler::class.' — discarded (DLQ off)')
            ->expectsOutputToContain('sync → '.SecondFanOutHandler::class)
            ->assertSuccessful();

        $this->assertSame([[SecondFanOutHandler::class, ['id' => 7]]], FanOutProbe::$received);
    }

    public function test_a_handler_joining_a_consumed_type_receives_it_without_restarting(): void
    {
        $message = $this->message('test.shared', ['id' => 7]);
        $message->shouldReceive('ack')->once();

        $channel = $this->channel(consumers: 2);
        $channel->shouldReceive('basic_cancel')->once()->with('test-service-test.shared');
        $channel->shouldReceive('wait')->twice()->andReturnUsing(
            function (): void {
                JoiningFanOutHandler::$activeTypes = ['test.shared'];
                usleep(1_100_000);

                throw new AMQPTimeoutException();
            },
            function () use ($message): void {
                ($this->consumer)($message);
                $this->restart();
            },
        );

        $this->listen([FirstFanOutHandler::class, JoiningFanOutHandler::class], $channel, rescan: 1)
            ->expectsOutputToContain('Listening for events: test.shared')
            ->expectsOutputToContain('Handlers changed for: test.shared')
            ->assertSuccessful();

        $this->assertSame([
            [FirstFanOutHandler::class, ['id' => 7]],
            [JoiningFanOutHandler::class, ['id' => 7]],
        ], FanOutProbe::$received);
    }

    /**
     * A channel consuming the shared queue the given number of times, keeping the last consumer callback.
     */
    private function channel(int $consumers = 1): MockInterface&AMQPChannel
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('getConnection')->andReturn(Mockery::mock(AbstractConnection::class, ['channel' => $channel]));
        $channel->shouldReceive('close', 'basic_qos', 'queue_declare', 'queue_bind');
        $channel->shouldReceive('is_consuming')->andReturn(true);
        $channel->shouldReceive('basic_consume')
            ->times($consumers)
            ->withArgs(fn (string $queue): bool => $queue === 'test-service.test.shared')
            ->andReturnUsing(function (...$arguments): void {
                $this->consumer = $arguments['callback'] ?? $arguments[6];
            });

        return $channel;
    }

    /**
     * Run the listener for the given handlers on the channel.
     *
     * @param  list<class-string<MessageHandler>>  $handlers
     */
    private function listen(array $handlers, AMQPChannel $channel, int $rescan = 0): PendingCommand
    {
        $this->app->instance(HandlerDiscovery::class, new class ($handlers) extends HandlerDiscovery
        {
            public function __construct(private readonly array $classes)
            {
            }

            public function discover(): array
            {
                return $this->classes;
            }
        });

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('channel')->andReturn($channel);
        $connection->shouldReceive('close')->zeroOrMoreTimes();
        $this->app->instance(Connection::class, $connection);

        return $this->artisan('microservice:listen', ['--memory' => 0, '--rescan' => $rescan]);
    }

    /**
     * A signed message of the type, as another service publishes it.
     */
    private function message(string $type, array $payload): MockInterface&AMQPMessage
    {
        $signer = $this->app->make(Signer::class);

        $envelope = ['id' => (string) Str::uuid(), 'type' => $type, 'service' => 'test-service', 'occurred_at' => now()->toIso8601String(), 'request_id' => null, 'payload' => $payload];
        $envelope['signature'] = $signer->signRaw(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $envelope['certificate'] = $signer->certificate();

        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getBody')->andReturn(json_encode($envelope));
        $message->shouldReceive('getRoutingKey')->andReturn($type);

        return $message;
    }

    /**
     * What `php artisan queue:restart` does, stopping the listener after the current message.
     */
    private function restart(): void
    {
        Cache::forever('illuminate:queue:restart', now()->getTimestamp());
    }
}

abstract class FanOutProbe implements MessageHandler
{
    /** @var list<array{class-string, array}> */
    public static array $received = [];

    public function __construct(public readonly array $payload)
    {
    }

    public static function type(): string|array
    {
        return 'test.shared';
    }

    public static function from(array $payload, string $type = ''): static
    {
        return new static($payload);
    }

    public function handle(): void
    {
        self::$received[] = [static::class, $this->payload];
    }
}

class FirstFanOutHandler extends FanOutProbe
{
}

class SecondFanOutHandler extends FanOutProbe
{
}

class FailingFanOutHandler extends FanOutProbe
{
    public function handle(): void
    {
        throw new RuntimeException('The handler failed.');
    }
}

class JoiningFanOutHandler extends FanOutProbe
{
    /** @var list<string> */
    public static array $activeTypes = [];

    public static function type(): array
    {
        return self::$activeTypes;
    }
}
