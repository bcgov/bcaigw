<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use OpenApi\Attributes as OA;
use Predis\Connection\ConnectionException;

final class HealthController extends Controller
{
    #[OA\Get(
        path: '/api/status',
        operationId: 'status',
        summary: 'Return application status',
        tags: ['System'],
        responses: [new OA\Response(response: 200, description: 'Application is running')]
    )]
    public function status(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => config('app.name'),
            'timestamp' => now()->toISOString(),
        ]);
    }

    #[OA\Get(
        path: '/api/ready',
        operationId: 'readiness',
        summary: 'Check required service connections',
        tags: ['System'],
        responses: [
            new OA\Response(response: 200, description: 'Application is ready'),
            new OA\Response(response: 503, description: 'A required service is unavailable'),
        ]
    )]
    public function readiness(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseIsReady(),
            'redis' => $this->redisIsReady(),
        ];
        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => $checks,
            'timestamp' => now()->toISOString(),
        ], $ready ? 200 : 503);
    }

    private function databaseIsReady(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (QueryException $exception) {
            Log::warning('Database readiness check failed.', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function redisIsReady(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (ConnectionException $exception) {
            Log::warning('Redis readiness check failed.', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
