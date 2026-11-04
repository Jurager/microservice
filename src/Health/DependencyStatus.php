<?php

declare(strict_types=1);

namespace Jurager\Microservice\Health;

use Throwable;

/**
 * What a dependency check found.
 */
final readonly class DependencyStatus
{
    /**
     * @param  bool  $degraded  Reachable, but in a state worth attention (e.g. dead-lettered messages).
     * @param  array<string, mixed>  $details  Extra figures reported with the status.
     */
    public function __construct(
        public bool $up,
        public ?float $latencyMs = null,
        public ?string $error = null,
        public bool $degraded = false,
        public array $details = [],
    ) {
    }

    /** @param  array<string, mixed>  $details */
    public static function up(float $latencyMs, array $details = [], bool $degraded = false): self
    {
        return new self(true, $latencyMs, null, $degraded, $details);
    }

    public static function down(Throwable $e): self
    {
        return new self(false, error: $e->getMessage());
    }

    /**
     * As shown in the report; the error message only when verbose — it may name hosts or credentials.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $verbose = false): array
    {
        return array_filter([
            'status' => $this->up ? 'up' : 'down',
            'latency_ms' => $this->latencyMs,
            ...$this->details,
            'error' => $verbose ? $this->error : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
