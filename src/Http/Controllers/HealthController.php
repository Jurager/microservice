<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Jurager\Microservice\Concerns\InteractsWithRedis;

class HealthController extends Controller
{
    use InteractsWithRedis;

    public function __invoke(): JsonResponse
    {
        $services = config('microservice.manifest.services', []);
        $ttl = config('microservice.manifest.ttl', 300);
        $prefix = $this->redisPrefix();

        $result = [];

        foreach ($services as $service) {
            $raw = $this->redis()->get($prefix."manifest:$service");

            if ($raw === null || $raw === false) {
                $result[$service] = [
                    'status' => 'missing',
                    'synced_at' => null,
                    'routes_count' => 0,
                    'base_urls' => [],
                    'timeout' => null,
                ];

                continue;
            }

            $manifest = json_decode($raw, true);
            $syncedAt = $manifest['synced_at'] ?? null;
            $remainingTtl = $this->redis()->ttl($prefix."manifest:$service");

            $status = 'ok';

            if ($remainingTtl !== -1 && $remainingTtl < ($ttl * 0.5)) {
                $status = 'stale';
            }

            $result[$service] = [
                'status' => $status,
                'synced_at' => $syncedAt,
                'expires_in' => $remainingTtl > 0 ? $remainingTtl : null,
                'routes_count' => count($manifest['routes'] ?? []),
                'base_urls' => $manifest['base_urls'] ?? [],
                'timeout' => $manifest['timeout'] ?? null,
            ];
        }

        return response()->json([
            'gateway' => config('microservice.name'),
            'services' => $result,
        ]);
    }
}
