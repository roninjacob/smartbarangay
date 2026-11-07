<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class IotProbeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'api_version' => '1',
            'request_id' => (string) Str::uuid(),
            'server_time' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
