<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tracing;

use Illuminate\Container\Attributes\Config;

/**
 * Collect timings for the current request or job.
 */
final class RequestTiming
{
    private const int LIMIT = 64;

    /** @var array<string, float> */
    private array $durations = [];

    /**
     * @param  bool  $tracing  Whether tracing is on at all.
     * @param  bool  $timings  Whether durations are collected and reported.
     */
    public function __construct(
        #[Config('microservice.tracing.enabled', true)] private readonly bool $tracing = true,
        #[Config('microservice.tracing.timings', true)] private readonly bool $timings = true,
    ) {
    }

    public function enabled(): bool
    {
        return $this->tracing && $this->timings;
    }

    public function add(string $name, float $milliseconds): void
    {
        if (! $this->enabled() || ! is_finite($milliseconds) || $milliseconds < 0 || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $name)) {
            return;
        }

        if (count($this->durations) < self::LIMIT || isset($this->durations[$name])) {
            $this->durations[$name] = ($this->durations[$name] ?? 0) + $milliseconds;
        }
    }

    /**
     * Add the document timings an upstream service reported, prefixed with its name.
     */
    public function upstream(string $service, ?string $header): void
    {
        preg_match_all('/(?:^|,)\s*(app_total|jsonapi_load|jsonapi_render);\s*dur=([0-9]+(?:\.[0-9]+)?)(?=\s*(?:,|$))/', $header ?? '', $matches, PREG_SET_ORDER);

        foreach ($matches as [, $metric, $duration]) {
            $this->add($service.'_'.$metric, (float) $duration);
        }
    }

    /**
     * Start over — at the beginning of a request, whatever ran before it in the process.
     */
    public function flush(): void
    {
        $this->durations = [];
    }

    public function header(): string
    {
        $metrics = [];

        foreach ($this->durations as $name => $duration) {
            $metrics[] = $name.';dur='.number_format($duration, 3, '.', '');
        }

        return implode(', ', $metrics);
    }
}
