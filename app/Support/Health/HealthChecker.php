<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthChecker
{
    /**
     * @return array{operational: bool, data: array<string, mixed>}
     */
    public function check(): array
    {
        $databaseOk = $this->databaseIsReachable();
        $cache = $this->cacheStatus();

        $operational = $databaseOk;
        $status = $operational ? 'ok' : 'unavailable';

        if ($operational && $cache === 'unavailable') {
            $status = 'degraded';
        }

        return [
            'operational' => $operational,
            'data' => [
                'status' => $status,
                'service' => 'marcaturshub-api',
                'api_version' => config('api.version'),
                'timestamp' => now()->toIso8601String(),
                'checks' => [
                    'application' => 'ok',
                    'database' => $databaseOk ? 'ok' : 'unavailable',
                    'cache' => $cache,
                ],
            ],
        ];
    }

    private function databaseIsReachable(): bool
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1 as health_check');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheStatus(): string
    {
        $store = config('cache.default');

        if (! in_array($store, ['redis', 'phpredis'], true) && config('queue.default') !== 'redis') {
            return 'skipped';
        }

        try {
            Redis::connection()->ping();

            return 'ok';
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
