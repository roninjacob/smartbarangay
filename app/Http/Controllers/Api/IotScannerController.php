<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IotScannerSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class IotScannerController extends Controller
{
    public function pair(Request $request, IotScannerSessions $scanners): JsonResponse
    {
        $body = $request->attributes->get('iot_body');
        $this->shape($body, ['challenge', 'boot_id']);
        abort_unless(Validator::make($body, ['challenge' => ['required', 'regex:/\ASBP1:[0-9a-f]{64}\z/'],
            'boot_id' => ['required', 'regex:/\A[0-9a-f]{32}\z/']])->passes(), 422);

        return response()->json($scanners->pair($request, $body));
    }

    public function scan(Request $request, IotScannerSessions $scanners): JsonResponse
    {
        $body = $request->attributes->get('iot_body');
        $this->shape($body, ['pairing_id', 'boot_id', 'scanner_session', 'sequence', 'captured_at_ms', 'payload']);
        abort_unless(Validator::make($body, [
            'pairing_id' => ['required', 'uuid'], 'boot_id' => ['required', 'regex:/\A[0-9a-f]{32}\z/'],
            'scanner_session' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'sequence' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'captured_at_ms' => ['required', 'integer', 'min:1000000000000', 'max:9999999999999'],
            'payload' => ['required', 'string', 'max:256'],
        ])->passes(), 422);
        foreach (['scanner_session', 'sequence', 'captured_at_ms'] as $field) {
            abort_unless(is_int($body[$field]), 422);
        }
        abort_if(strlen($body['payload']) > 256, 422);

        return response()->json($scanners->scan($request, $body));
    }

    private function shape(array $body, array $keys): void
    {
        $actual = array_keys($body);
        sort($actual);
        sort($keys);
        abort_unless($actual === $keys, 422);
    }
}
