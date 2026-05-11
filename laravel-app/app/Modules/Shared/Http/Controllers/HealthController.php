<?php

namespace App\Modules\Shared\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'app',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function database(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            return response()->json(['status' => 'ok', 'driver' => config('database.default')]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function redis(): JsonResponse
    {
        try {
            Redis::ping();
            return response()->json(['status' => 'ok', 'driver' => 'redis']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function queue(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'driver' => config('queue.default'),
        ]);
    }

    public function waGateway(): JsonResponse
    {
        try {
            $url = rtrim(config('services.wa_gateway.url', env('WA_GATEWAY_URL', 'http://wa-gateway:3001')), '/') . '/health';
            $response = Http::timeout(5)->get($url);

            if ($response->successful()) {
                return response()->json(['status' => 'ok', 'gateway_status' => $response->json()]);
            }

            return response()->json(['status' => 'error', 'http_status' => $response->status()], 500);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
