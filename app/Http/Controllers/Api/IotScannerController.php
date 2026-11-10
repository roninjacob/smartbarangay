<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IotAutomaticScanner;
use App\Services\IotScannerSessions;
use App\Services\IotTelemetry;
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
        $keys = ['boot_id', 'scanner_session', 'sequence', 'captured_at_ms', 'payload'];
        $legacy = array_key_exists('pairing_id', $body);
        $this->shape($body, $legacy ? array_merge(['pairing_id'], $keys) : $keys);
        abort_unless(Validator::make($body, [
            'pairing_id' => ['sometimes', 'required', 'uuid'], 'boot_id' => ['required', 'regex:/\A[0-9a-f]{32}\z/'],
            'scanner_session' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'sequence' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'captured_at_ms' => ['required', 'integer', 'min:1000000000000', 'max:9999999999999'],
            'payload' => ['required', 'string', 'max:256'],
        ])->passes(), 422);
        foreach (['scanner_session', 'sequence', 'captured_at_ms'] as $field) {
            abort_unless(is_int($body[$field]), 422);
        }
        abort_if(strlen($body['payload']) > 256, 422);

        return response()->json($legacy ? $scanners->scan($request, $body) : app(IotAutomaticScanner::class)->scan($request, $body));
    }

    public function status(Request $request, IotAutomaticScanner $scanner): JsonResponse
    {
        $body = $request->attributes->get('iot_body');
        $keys = ['boot_id', 'captured_at_ms', 'camera_state'];
        $this->shape($body, array_key_exists('telemetry', $body) ? array_merge($keys, ['telemetry']) : $keys);
        if (array_key_exists('telemetry', $body)) {
            $body['telemetry'] = app(IotTelemetry::class)->validate($body['telemetry']);
        }
        $this->common($body);
        abort_unless(in_array($body['camera_state'], ['ready', 'camera_error', 'detection_error'], true), 422);

        return response()->json($scanner->status($request, $body));
    }

    public function preview(Request $request, IotAutomaticScanner $scanner): JsonResponse
    {
        $body = $request->attributes->get('iot_body');
        $this->shape($body, ['boot_id', 'captured_at_ms', 'lease_id', 'sequence', 'image']);
        $this->common($body);
        abort_unless(Validator::make($body, ['lease_id' => ['required', 'uuid'],
            'sequence' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'image' => ['required', 'string', 'max:43692']])->passes() && is_int($body['sequence']), 422);

        return response()->json($scanner->upload($request, $body));
    }

    private function common(array $body): void
    {
        abort_unless(is_string($body['boot_id']) && preg_match('/\A[0-9a-f]{32}\z/', $body['boot_id'])
            && is_int($body['captured_at_ms']) && $body['captured_at_ms'] >= 1000000000000 && $body['captured_at_ms'] <= 9999999999999, 422);
    }

    private function shape(array $body, array $keys): void
    {
        $actual = array_keys($body);
        sort($actual);
        sort($keys);
        abort_unless($actual === $keys, 422);
    }
}
