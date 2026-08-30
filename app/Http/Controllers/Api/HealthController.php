<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $apiStatus = 'ok';

        try {
            DB::connection()->getPdo();
            $databaseStatus = 'ok';
        } catch (Throwable) {
            $databaseStatus = 'error';
        }

        try {
            Redis::connection()->ping();
            $redisStatus = 'ok';
        } catch (Throwable) {
            $redisStatus = 'error';
        }

        $allOk = $apiStatus === 'ok' && $databaseStatus === 'ok' && $redisStatus === 'ok';

        return response()->json([
            'status' => $allOk ? 'ok' : 'degraded',
            'services' => [
                'api' => $apiStatus,
                'database' => $databaseStatus,
                'redis' => $redisStatus,
            ],
        ]);
    }
}
