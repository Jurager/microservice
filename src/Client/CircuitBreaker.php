<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\InteractsWithTime;
use Jurager\Microservice\Exceptions\ServiceUnavailableException;

/**
 * Block service requests after repeated failures and allow a probe after cooldown.
 */
class CircuitBreaker
{
    use InteractsWithTime;

    public const string CLOSED = 'closed';

    public const string OPEN = 'open';

    public const string HALF_OPEN = 'half-open';

    /**
     * @param  int  $threshold  Consecutive failures within the window that open the breaker; zero switches it off.
     * @param  int  $timeout  Seconds the breaker stays open before a probe is let through.
     * @param  int  $window  Seconds a failure streak is remembered.
     */
    public function __construct(
        private readonly Cache $cache,
        #[Config('microservice.circuit_breaker.threshold', 0)] private readonly int $threshold = 0,
        #[Config('microservice.circuit_breaker.timeout', 30)] private readonly int $timeout = 30,
        #[Config('microservice.circuit_breaker.window', 60)] private readonly int $window = 60,
    ) {
    }

    /**
     * Whether the breaker is switched on.
     */
    public function enabled(): bool
    {
        return $this->threshold > 0;
    }

    /**
     * Throw while the breaker is open; once the cooldown is over, let one probe through.
     *
     * @throws ServiceUnavailableException
     */
    public function ensureClosed(string $service): void
    {
        if (! $this->enabled() || ($openedAt = $this->openedAt($service)) === null) {
            return;
        }

        if ($this->currentTime() - $openedAt < $this->timeout) {
            throw new ServiceUnavailableException($service, "Circuit breaker is open for service [$service]");
        }

        // add() writes only when the key is missing, so exactly one process gets to probe.
        if (! $this->cache->add($this->key($service, 'probe'), true, max(1, $this->timeout))) {
            throw new ServiceUnavailableException($service, "Circuit breaker is open for service [$service]: a probe is in progress");
        }
    }

    /**
     * A request was answered: the failure streak, and an open breaker, are over.
     */
    public function recordSuccess(string $service): void
    {
        if (! $this->enabled()) {
            return;
        }

        $keys = [$this->key($service, 'failures'), $this->key($service, 'opened')];

        // Reading first keeps the common case — nothing to reset — at one round trip.
        if (array_filter($this->cache->many($keys), static fn (mixed $value): bool => $value !== null && $value !== 0) === []) {
            return;
        }

        foreach ([...$keys, $this->key($service, 'probe')] as $key) {
            $this->cache->forget($key);
        }
    }

    public function recordFailure(string $service): void
    {
        if (! $this->enabled()) {
            return;
        }

        // A failed probe re-opens the breaker for another cooldown.
        if ($this->openedAt($service) !== null) {
            $this->open($service);

            return;
        }

        // add() only writes when the key is missing, so concurrent failures never reset each other's count.
        $this->cache->add($this->key($service, 'failures'), 0, $this->window);

        if ((int) $this->cache->increment($this->key($service, 'failures')) >= $this->threshold) {
            $this->open($service);
        }
    }

    /**
     * Current state of the breaker for a service, or null while it is switched off.
     *
     * @return array{state: string, failures: int, threshold: int, opens_until?: string}|null
     */
    public function state(string $service): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $openedAt = $this->openedAt($service);

        $result = [
            'state' => match (true) {
                $openedAt === null => self::CLOSED,
                $this->currentTime() - $openedAt < $this->timeout => self::OPEN,
                default => self::HALF_OPEN,
            },
            'failures' => (int) $this->cache->get($this->key($service, 'failures')),
            'threshold' => $this->threshold,
        ];

        if ($result['state'] === self::OPEN) {
            $result['opens_until'] = Carbon::createFromTimestamp($openedAt + $this->timeout)->toIso8601String();
        }

        return $result;
    }

    private function open(string $service): void
    {
        // Kept past the cooldown, so the breaker stays half-open until a probe settles it.
        $this->cache->put($this->key($service, 'opened'), $this->currentTime(), $this->timeout + $this->window);
        $this->cache->forget($this->key($service, 'probe'));
        $this->cache->forget($this->key($service, 'failures'));
    }

    private function openedAt(string $service): ?int
    {
        $openedAt = $this->cache->get($this->key($service, 'opened'));

        return $openedAt !== null ? (int) $openedAt : null;
    }

    private function key(string $service, string $suffix): string
    {
        return "microservice:circuit:$service:$suffix";
    }
}
