<?php

namespace App\Services;

use App\Models\IotDevice;
use App\Models\IotDeviceState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class IotTelemetry
{
    public const CATEGORIES = [
        'ok', 'clock_unsynchronized', 'clock_stale', 'wifi_disconnected', 'dns_timeout',
        'dns_resolution_failed', 'internal_heap_pressure', 'tls_allocation_failed',
        'internal_allocation_observed', 'preview_psram_allocation_failed', 'tcp_connect_timeout',
        'tcp_or_tls_setup_failed', 'tls_certificate_rejected', 'tls_handshake_timeout',
        'tls_handshake_failed', 'http_read_timeout', 'http_connection_failed', 'application_http_error',
        'http_response_bounds_or_read_failed', 'application_invalid_json', 'application_invalid_response',
        'deadline_exceeded', 'stale_capture_dropped', 'connection_closed_before_request',
        'preview_yielded_to_scan', 'http_initialization_failed', 'signing_failed', 'local_configuration_or_body_invalid',
    ];

    public const NUMBERS = [
        'uptime_ms', 'heap', 'largest', 'psram', 'attempts', 'decodes', 'capture_fail',
        'avg_us', 'p95_us', 'capture_us', 'convert_us', 'gray_us', 'detect_us', 'decode_us',
        'preview_copy_us', 'preview_sent', 'http_ms', 'alloc_fail', 'reset',
    ];

    public function validate(mixed $telemetry): array
    {
        if ($telemetry instanceof \stdClass) {
            $telemetry = get_object_vars($telemetry);
        }
        abort_unless(is_array($telemetry) && ! array_is_list($telemetry), 422);
        $allowed = array_merge(self::NUMBERS, ['rssi', 'http_code', 'error', 'firmware']);
        abort_if(array_diff(array_keys($telemetry), $allowed), 422);
        $rules = array_fill_keys(self::NUMBERS, ['sometimes', 'integer', 'min:0', 'max:4294967295']);
        $rules['uptime_ms'] = ['sometimes', 'integer', 'min:0', 'max:9999999999999'];
        $rules['rssi'] = ['sometimes', 'integer', 'between:-127,0'];
        $rules['http_code'] = ['sometimes', 'integer', 'between:-20,599'];
        $rules['error'] = ['sometimes', 'string', 'in:'.implode(',', self::CATEGORIES)];
        $rules['firmware'] = ['sometimes', 'string', 'regex:/\Asb-iot-[0-9]{1,2}\.[0-9]{1,2}\z/'];
        abort_unless(Validator::make($telemetry, $rules)->passes(), 422);
        foreach (array_merge(self::NUMBERS, ['rssi', 'http_code']) as $key) {
            abort_if(array_key_exists($key, $telemetry) && ! is_int($telemetry[$key]), 422);
        }

        return $telemetry;
    }

    public function heartbeat(IotDeviceState $state, array $body): void
    {
        $previous = $state->telemetry ?? [];
        if ($state->wasRecentlyCreated || $state->boot_id !== $body['boot_id']) {
            $this->event($state->device_id, 'boot');
            $state->telemetry = null;
            $state->telemetry_at = null;
        } elseif ($state->offline_reported_at || $state->heartbeat_at?->lte(now()->subSeconds(15))) {
            $this->event($state->device_id, 'online_observed');
        }
        if ($state->camera_state !== $body['camera_state']) {
            $this->event($state->device_id, $body['camera_state']);
        }
        if (isset($body['telemetry'])) {
            $metrics = $body['telemetry'];
            if (isset($metrics['error']) && $metrics['error'] !== ($previous['error'] ?? null)) {
                $this->event($state->device_id, 'transport', ['category' => $metrics['error'], 'http' => $metrics['http_code'] ?? null]);
            }
            $state->telemetry = $metrics;
            $state->telemetry_at = now('UTC');
        }
        $state->offline_reported_at = null;
    }

    public function scan(IotDeviceState $state, bool $valid): void
    {
        $state->counts_since ??= now('UTC');
        $state->scan_count++;
        if ($valid) {
            $state->valid_count++;
        } else {
            $state->invalid_count++;
        }
        $this->event($state->device_id, $valid ? 'scan_valid' : 'scan_invalid');
    }

    private function event(string $id, string $kind, array $details = []): void
    {
        DB::table('iot_device_diagnostics')->insert(['device_id' => $id, 'kind' => $kind,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'recorded_at' => now('UTC')]);
        // All callers hold the device lock. Bounds apply immediately, independently of cron.
        $cutoff = DB::table('iot_device_diagnostics')->where('device_id', $id)->orderByDesc('id')->offset(499)->value('id');
        if ($cutoff) {
            DB::table('iot_device_diagnostics')->where('device_id', $id)->where('id', '<', $cutoff)->delete();
        }
    }

    public function prune(): void
    {
        DB::table('iot_device_diagnostics')->where('recorded_at', '<=', now('UTC')->subDays(7))->delete();
        IotDeviceState::whereNull('offline_reported_at')->where('heartbeat_at', '<=', now('UTC')->subSeconds(15))
            ->eachById(function ($candidate) {
                DB::transaction(function () use ($candidate) {
                    $device = IotDevice::lockForUpdate()->find($candidate->device_id);
                    $state = IotDeviceState::find($candidate->device_id);
                    if ($device && ! $device->revoked_at && $state && ! $state->offline_reported_at
                        && $state->heartbeat_at?->lte(now()->subSeconds(15))) {
                        $this->event($device->id, 'offline_observed');
                        $state->forceFill(['offline_reported_at' => now('UTC')])->save();
                    }
                });
            }, 100, 'device_id');
    }
}
