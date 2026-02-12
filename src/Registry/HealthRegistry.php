<?php

declare(strict_types=1);

namespace Jurager\Microservice\Registry;

use Jurager\Microservice\Concerns\InteractsWithRedis;

class HealthRegistry
{
    use InteractsWithRedis;

    protected function healthKey(string $service, string $url): string
    {
        return $this->redisPrefix()."health:$service:".md5($url);
    }

    /**
     * Get all configured instances for a service.
     *
     * @return string[]
     */
    public function getInstances(string $service): array
    {
        return config("microservice.services.$service.base_urls", []);
    }

    /**
     * Get only healthy instances for a service.
     *
     * @return string[]
     */
    public function getHealthyInstances(string $service): array
    {
        return array_values(array_filter(
            $this->getInstances($service),
            fn (string $url) => $this->isHealthy($this->getInstanceHealth($service, $url)),
        ));
    }

    public function markFailure(string $service, string $url): void
    {
        $key = $this->healthKey($service, $url);
        $ttl = config('microservice.health.recovery_timeout', 30) * 2;

        $raw = $this->redis()->get($key);
        $data = $raw ? json_decode($raw, true) : ['failures' => 0, 'last_failure' => 0];

        $data['failures']++;
        $data['last_failure'] = time();

        $this->redis()->setex($key, $ttl, json_encode($data));
    }

    public function markSuccess(string $service, string $url): void
    {
        $this->redis()->del($this->healthKey($service, $url));
    }

    /**
     * @return array{failures: int, last_failure: int}|null
     */
    public function getInstanceHealth(string $service, string $url): ?array
    {
        $raw = $this->redis()->get($this->healthKey($service, $url));

        if ($raw === null || $raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, array<int, array{url: string, failures: int, last_failure: int|null, healthy: bool}>>
     */
    public function getAllHealth(): array
    {
        $result = [];

        foreach (config('microservice.services', []) as $name => $config) {
            $result[$name] = [];

            foreach ($config['base_urls'] ?? [] as $url) {
                $data = $this->getInstanceHealth($name, $url);

                $result[$name][] = [
                    'url' => $url,
                    'failures' => $data['failures'] ?? 0,
                    'last_failure' => $data['last_failure'] ?? null,
                    'healthy' => $this->isHealthy($data),
                ];
            }
        }

        return $result;
    }

    protected function isHealthy(?array $data): bool
    {
        if ($data === null) {
            return true;
        }

        $threshold = config('microservice.health.failure_threshold', 3);

        if (($data['failures'] ?? 0) < $threshold) {
            return true;
        }

        $recoveryTimeout = config('microservice.health.recovery_timeout', 30);

        return (time() - ($data['last_failure'] ?? 0)) >= $recoveryTimeout;
    }
}
