<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\Bus\QueueDeclaration;
use Jurager\Microservice\Bus\Topology;
use Jurager\Microservice\Tests\TestCase;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Wire\AMQPTable;

class TopologyTest extends TestCase
{
    private const array ARGUMENTS = [
        'x-dead-letter-exchange' => 'events.dlx',
        'x-dead-letter-routing-key' => 'ams.oms.order.created',
    ];

    public function test_dead_letters_are_routed_by_the_queue_name(): void
    {
        [$main, $sides] = $this->channels(new RecordingChannel);

        $this->assertSame(QueueDeclaration::Declared, $this->topology()->declareQueue($main, 'oms.order.created'));

        $this->assertSame([
            ['queue_declare', 'ams.oms.order.created.dlq', []],
            ['queue_bind', 'ams.oms.order.created.dlq', 'events.dlx', 'ams.oms.order.created'],
            ['queue_declare', 'ams.oms.order.created', self::ARGUMENTS],
            ['queue_bind', 'ams.oms.order.created', 'events', 'oms.order.created'],
        ], $sides[0]->calls);
        $this->assertTrue($sides[0]->closed);
        $this->assertSame([], $main->calls, 'The consuming channel survives a refused declaration.');
    }

    public function test_without_dead_lettering_only_the_queue_is_declared(): void
    {
        [$main, $sides] = $this->channels(new RecordingChannel);

        (new Topology('ams', 'events', false))->declareQueue($main, 'oms.order.created');

        $this->assertSame([
            ['queue_declare', 'ams.oms.order.created', []],
            ['queue_bind', 'ams.oms.order.created', 'events', 'oms.order.created'],
        ], $sides[0]->calls);
    }

    public function test_an_empty_queue_declared_with_other_arguments_is_recreated(): void
    {
        [$main, $sides] = $this->channels(
            new RecordingChannel(['queue_declare ams.oms.order.created' => 406]),
            new RecordingChannel,
            new RecordingChannel,
        );

        $this->assertSame(QueueDeclaration::Recreated, $this->topology()->declareQueue($main, 'oms.order.created'));

        $this->assertSame([
            ['queue_delete', 'ams.oms.order.created', false, true],
            ['queue_declare', 'ams.oms.order.created', self::ARGUMENTS],
            ['queue_bind', 'ams.oms.order.created', 'events', 'oms.order.created'],
        ], $sides[1]->calls, 'Deleted only while empty.');
        $this->assertSame([
            ['queue_unbind', 'ams.oms.order.created.dlq', 'events.dlx', 'oms.order.created'],
        ], $sides[2]->calls, 'The binding by event type is dropped.');
        $this->assertSame([], $main->calls);
    }

    public function test_a_queue_with_other_arguments_and_messages_is_kept(): void
    {
        [$main, $sides] = $this->channels(
            new RecordingChannel(['queue_declare ams.oms.order.created' => 406]),
            new RecordingChannel(['queue_delete ams.oms.order.created' => 406]),
        );

        $this->assertSame(QueueDeclaration::Outdated, $this->topology()->declareQueue($main, 'oms.order.created'));

        $this->assertSame([], $sides[1]->calls);
        $this->assertTrue($sides[1]->closed);
    }

    public function test_other_refusals_are_not_taken_for_outdated_arguments(): void
    {
        [$main] = $this->channels(new RecordingChannel(['queue_declare ams.oms.order.created' => 403]));

        $this->expectException(AMQPProtocolChannelException::class);

        $this->topology()->declareQueue($main, 'oms.order.created');
    }

    private function topology(): Topology
    {
        return new Topology('ams', 'events', true, 'events.dlx');
    }

    /**
     * The consuming channel, and the channels its connection opens next, in order.
     *
     * @return array{RecordingChannel, list<RecordingChannel>}
     */
    private function channels(RecordingChannel ...$sides): array
    {
        $connection = Mockery::mock(AbstractConnection::class);
        $connection->shouldReceive('channel')->times(count($sides))->andReturn(...$sides);

        return [new RecordingChannel(owner: $connection), $sides];
    }
}

class RecordingChannel extends AMQPChannel
{
    /** @var list<array> */
    public array $calls = [];

    public bool $closed = false;

    /**
     * @param  array<string, int>  $refusals  Reply codes the broker answers "method queue" with.
     */
    public function __construct(private readonly array $refusals = [], private readonly ?AbstractConnection $owner = null)
    {
    }

    public function getConnection()
    {
        return $this->owner;
    }

    public function queue_declare($queue = '', $passive = false, $durable = false, $exclusive = false, $auto_delete = true, $nowait = false, $arguments = [], $ticket = null)
    {
        $this->call('queue_declare', $queue, $arguments instanceof AMQPTable ? $arguments->getNativeData() : $arguments);

        return null;
    }

    public function queue_bind($queue, $exchange, $routing_key = '', $nowait = false, $arguments = [], $ticket = null)
    {
        $this->call('queue_bind', $queue, $exchange, $routing_key);

        return null;
    }

    public function queue_unbind($queue, $exchange, $routing_key = '', $arguments = [], $ticket = null)
    {
        $this->call('queue_unbind', $queue, $exchange, $routing_key);

        return null;
    }

    public function queue_delete($queue = '', $if_unused = false, $if_empty = false, $nowait = false, $ticket = null)
    {
        $this->call('queue_delete', $queue, $if_unused, $if_empty);

        return null;
    }

    public function close($reply_code = 0, $reply_text = '', $method_sig = [0, 0])
    {
        $this->closed = true;

        return null;
    }

    private function call(string $method, mixed ...$arguments): void
    {
        $code = $this->refusals["$method {$arguments[0]}"] ?? null;

        if ($code !== null) {
            throw new AMQPProtocolChannelException($code, 'PRECONDITION_FAILED', [50, 10]);
        }

        $this->calls[] = [$method, ...$arguments];
    }
}
