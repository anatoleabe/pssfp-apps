<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $services = [
            'database' => $this->checkDatabase(),
            'redis' => $this->shouldCheckRedis() ? $this->checkRedis() : 'unknown',
        ];

        $hasDown = in_array('down', $services, true);

        return response()->json([
            'status' => $hasDown ? 'degraded' : 'ok',
            'timestamp' => now()->toIso8601String(),
            'version' => config('app.version', '0.0.0'),
            'services' => $services,
        ], $hasDown ? 503 : 200);
    }

    private function shouldCheckRedis(): bool
    {
        return config('cache.default') === 'redis'
            || config('queue.default') === 'redis'
            || config('session.driver') === 'redis';
    }

    private function checkDatabase(): string
    {
        try {
            DB::connection()->getPdo();

            return 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }

    private function checkRedis(): string
    {
        try {
            Redis::ping();

            return 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }
}
