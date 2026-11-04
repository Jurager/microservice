<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Determine whether a failed request can be retried and calculate its delay.
 */
final readonly class RetryPolicy
{
    /**
     * cURL errors raised before a single byte of the request was sent.
     */
    private const array NEVER_SENT = [CURLE_COULDNT_RESOLVE_PROXY, CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT];

    /**
     * Server errors that answer the same however often the request is repeated.
     */
    private const array PERMANENT = [501, 505];

    /**
     * @param  int  $maxRetries  Retries after the first attempt; zero switches retrying off.
     * @param  int  $delay  Milliseconds before the first retry.
     * @param  float  $multiplier  Factor each further delay grows by.
     */
    public function __construct(
        #[Config('microservice.retries.max', 0)] public int $maxRetries = 0,
        #[Config('microservice.retries.delay', 100)] public int $delay = 100,
        #[Config('microservice.retries.multiplier', 2.0)] public float $multiplier = 2.0,
    ) {
    }

    public function enabled(): bool
    {
        return $this->maxRetries > 0;
    }

    /**
     * Attempts in all, the first one included — what Laravel's retry() counts.
     */
    public function attempts(): int
    {
        return $this->maxRetries + 1;
    }

    /**
     * Milliseconds to wait before the given attempt's retry (1-based).
     */
    public function delayFor(int $attempt): int
    {
        return (int) ($this->delay * ($this->multiplier ** ($attempt - 1)));
    }

    /**
     * Whether a failure is worth another attempt.
     *
     * @param  bool  $repeatable  The request is safe, or a write the peer deduplicates.
     */
    public function shouldRetry(Throwable $failure, bool $repeatable): bool
    {
        if ($failure instanceof RequestException) {
            $status = $failure->response->status();

            return $repeatable && $status >= 500 && ! in_array($status, self::PERMANENT, true);
        }

        if ($failure instanceof ConnectionException) {
            return $repeatable || self::neverSent($failure);
        }

        return false;
    }

    private static function neverSent(ConnectionException $failure): bool
    {
        $cause = $failure->getPrevious();

        return $cause instanceof ConnectException
            && in_array($cause->getHandlerContext()['errno'] ?? null, self::NEVER_SENT, true);
    }
}
