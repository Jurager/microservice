<?php

declare(strict_types=1);

namespace Jurager\Microservice\Queue;

use Jurager\Microservice\Bus\Contracts\MessageHandler;

class MessageDeserializer
{
    /** @var array<string, class-string<MessageHandler>> */
    private array $handlers;

    public function __construct()
    {
        $this->handlers = collect(config('messages', []))
            ->mapWithKeys(fn (string $class) => [$class::type() => $class])
            ->all();
    }

    /**
     * Translate a raw RabbitMQ message envelope into a standard Laravel queued job payload.
     *
     * Expected input: {"type": "sfm.site.updated", "payload": {...}}
     *
     * @return array{displayName: string, job: string, data: array{commandName: string, command: string}}
     *
     * @throws \JsonException              On malformed JSON.
     * @throws \UnexpectedValueException   On missing or unregistered message type.
     */
    public function deserialize(string $payload): array
    {
        $message = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        $type = $message['type'] ?? throw new \UnexpectedValueException('Missing message type');

        $jobClass = $this->handlers[$type]
            ?? throw new \UnexpectedValueException("No handler registered for message type [{$type}]");

        $job = $jobClass::fromMessage($message['payload'] ?? []);

        return [
            'displayName' => $job::class,
            'job'         => 'Illuminate\Queue\CallQueuedHandler@call',
            'data'        => [
                'commandName' => $job::class,
                'command'     => serialize($job),
            ],
        ];
    }
}
