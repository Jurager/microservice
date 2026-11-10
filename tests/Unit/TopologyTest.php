<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\Bus\Topology;
use Jurager\Microservice\Tests\TestCase;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

class TopologyTest extends TestCase
{
    public function test_dead_letters_are_routed_by_the_queue_name(): void
    {
        $declared = [];
        $bindings = [];

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare')->andReturnUsing(
            function (string $queue, $passive = false, $durable = false, $exclusive = false, $autoDelete = true, $nowait = false, $arguments = []) use (&$declared): void {
                $declared[$queue] = $arguments instanceof AMQPTable ? $arguments->getNativeData() : $arguments;
            },
        );
        $channel->shouldReceive('queue_bind')->andReturnUsing(
            function (string $queue, string $exchange, string $routingKey) use (&$bindings): void {
                $bindings[] = [$queue, $exchange, $routingKey];
            },
        );

        (new Topology('ams', 'events', true, 'events.dlx'))->declareQueue($channel, 'oms.order.created');

        $this->assertSame([
            'x-dead-letter-exchange' => 'events.dlx',
            'x-dead-letter-routing-key' => 'ams.oms.order.created',
        ], $declared['ams.oms.order.created']);
        $this->assertSame([], $declared['ams.oms.order.created.dlq']);

        $this->assertSame([
            ['ams.oms.order.created.dlq', 'events.dlx', 'ams.oms.order.created'],
            ['ams.oms.order.created', 'events', 'oms.order.created'],
        ], $bindings);
    }

    public function test_without_dead_lettering_only_the_queue_is_declared(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare')->once()
            ->withArgs(fn (string $queue, $passive = false, $durable = false, $exclusive = false, $autoDelete = true, $nowait = false, $arguments = []): bool => $queue === 'ams.oms.order.created' && $arguments === []);
        $channel->shouldReceive('queue_bind')->once()->with('ams.oms.order.created', 'events', 'oms.order.created');

        (new Topology('ams', 'events', false))->declareQueue($channel, 'oms.order.created');

        $this->addToAssertionCount(1);
    }
}
