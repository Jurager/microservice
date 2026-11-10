<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Jurager\Microservice\Bus\Connection;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Tests\TestCase;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPTimeoutException;

/**
 * Start against queues declared by an earlier version with other arguments.
 */
class ListenCommandOutdatedQueueTest extends TestCase
{
    public function test_an_empty_outdated_queue_is_recreated_and_consumed(): void
    {
        $refused = $this->sideChannel();
        $refused->shouldReceive('queue_declare')->with('test-service.test.outdated', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andThrow(new AMQPProtocolChannelException(406, "PRECONDITION_FAILED - inequivalent arg 'x-dead-letter-routing-key'", [50, 10]));

        $recreating = $this->sideChannel();
        $recreating->shouldReceive('queue_delete')->once()->with('test-service.test.outdated', false, true);

        $unbinding = $this->sideChannel();
        $unbinding->shouldReceive('queue_unbind')->once()->with('test-service.test.outdated.dlq', 'events.dlx', 'test.outdated');

        $this->listen([$refused, $recreating, $unbinding])
            ->expectsOutputToContain('Recreated queue test-service.test.outdated')
            ->expectsOutputToContain('Listening for events: test.outdated')
            ->assertSuccessful();
    }

    public function test_an_outdated_queue_holding_messages_is_consumed_as_it_is(): void
    {
        $refused = $this->sideChannel();
        $refused->shouldReceive('queue_declare')->with('test-service.test.outdated', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any())
            ->andThrow(new AMQPProtocolChannelException(406, "PRECONDITION_FAILED - inequivalent arg 'x-dead-letter-routing-key'", [50, 10]));

        $keeping = $this->sideChannel();
        $keeping->shouldReceive('queue_delete')->once()->andThrow(new AMQPProtocolChannelException(406, 'PRECONDITION_FAILED - queue not empty', [50, 40]));
        $keeping->shouldNotReceive('queue_declare');

        $this->listen([$refused, $keeping])
            ->expectsOutputToContain('still holds messages')
            ->expectsOutputToContain('Listening for events: test.outdated')
            ->assertSuccessful();
    }

    /**
     * Run the listener until the first wait, with declarations on the given channels in turn.
     *
     * @param  list<AMQPChannel>  $sides
     */
    private function listen(array $sides): mixed
    {
        $this->app->instance(HandlerDiscovery::class, new class extends HandlerDiscovery
        {
            public function discover(): array
            {
                return [OutdatedQueueProbeHandler::class];
            }
        });

        $amqp = Mockery::mock(AbstractConnection::class);
        $amqp->shouldReceive('channel')->times(count($sides))->andReturn(...$sides);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('getConnection')->andReturn($amqp);
        $channel->shouldReceive('exchange_declare', 'basic_qos');
        $channel->shouldReceive('basic_consume')->once()->withArgs(fn (string $queue): bool => $queue === 'test-service.test.outdated');
        $channel->shouldReceive('is_consuming')->andReturn(true);
        $channel->shouldReceive('wait')->once()->andReturnUsing(function (): void {
            Cache::forever('illuminate:queue:restart', now()->getTimestamp());

            throw new AMQPTimeoutException();
        });

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('channel')->andReturn($channel);
        $connection->shouldReceive('close')->zeroOrMoreTimes();
        $this->app->instance(Connection::class, $connection);

        Cache::forget('illuminate:queue:restart');

        return $this->artisan('microservice:listen', ['--memory' => 0, '--rescan' => 0]);
    }

    private function sideChannel(): AMQPChannel
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare', 'queue_bind')->byDefault();
        $channel->shouldReceive('close')->once();

        return $channel;
    }
}

class OutdatedQueueProbeHandler implements MessageHandler
{
    public static function type(): string
    {
        return 'test.outdated';
    }

    public static function from(array $payload, string $type = ''): static
    {
        return new static;
    }

    public function handle(): void
    {
    }
}
