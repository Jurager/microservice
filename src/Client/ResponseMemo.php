<?php

declare(strict_types=1);

namespace Jurager\Microservice\Client;

/**
 * Cache safe responses for the current request or job.
 */
class ResponseMemo
{
    /** @var array<string, ServiceResponse> */
    private array $responses = [];

    /** @var array<string, array<string, true>> Memo keys by the service that answered them. */
    private array $byService = [];

    public function get(PendingServiceRequest $request): ?ServiceResponse
    {
        $key = $this->key($request);

        return $key !== null ? ($this->responses[$key] ?? null) : null;
    }

    /**
     * Remember successful reads and invalidate service responses after successful writes.
     */
    public function remember(PendingServiceRequest $request, ServiceResponse $response): void
    {
        if (! $response->successful()) {
            return;
        }

        $service = $request->getService();

        if (! $request->isSafe()) {
            $this->forget($service);

            return;
        }

        if (($key = $this->key($request)) !== null) {
            $this->responses[$key] = $response;
            $this->byService[$service][$key] = true;
        }
    }

    /**
     * Drop the responses of one service, or of every service.
     */
    public function forget(?string $service = null): void
    {
        if ($service === null) {
            $this->responses = $this->byService = [];

            return;
        }

        foreach (array_keys($this->byService[$service] ?? []) as $key) {
            unset($this->responses[$key]);
        }

        unset($this->byService[$service]);
    }

    /**
     * Key a request is memoized under, or null when it must not be.
     */
    private function key(PendingServiceRequest $request): ?string
    {
        if (! $request->shouldMemoize() || $request->getMultipart() !== null) {
            return null;
        }

        $headers = array_change_key_case($request->getHeaders());
        ksort($headers);

        return md5(implode('|', [
            $request->getService(),
            $request->getMethod(),
            $request->target(),
            json_encode($headers),
            $request->getBody() ?? '',
        ]));
    }
}
