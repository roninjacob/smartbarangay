<?php

namespace App\Services;

use App\Models\IotDevice;
use App\Models\IotDeviceState;
use App\Models\IotScannerPairing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IotMonitoring
{
    public function devices(): array
    {
        return IotDevice::orderBy('name')->orderBy('id')->get(['id', 'name', 'revoked_at'])
            ->map(fn ($device) => ['id' => $device->id, 'name' => $device->name, 'revoked' => $device->revoked_at !== null])->all();
    }

    public function report(string $id, int $page): array
    {
        return DB::transaction(function () use ($id, $page) {
            $device = IotDevice::select(['id', 'name', 'revoked_at', 'credential_version'])->findOrFail($id);
            $state = IotDeviceState::find($id);
            $lease = IotScannerPairing::find($id);
            $online = ! $device->revoked_at && $state && $state->heartbeat_at?->gt(now()->subSeconds(15));
            $preview = $lease && $lease->automatic && $lease->preview_until?->isFuture()
                && app(IotScannerSessions::class)->live($lease, $device);
            $events = DB::table('iot_device_diagnostics')->where('device_id', $id)
                ->where('recorded_at', '>', now('UTC')->subDays(7))->orderByDesc('id')->paginate(15, ['kind', 'details', 'recorded_at'], 'page', $page);

            return ['device' => ['name' => $device->name, 'revoked' => $device->revoked_at !== null,
                'online' => (bool) $online, 'last_seen_at' => $state?->last_seen_at->toIso8601String(),
                'heartbeat_at' => $state?->heartbeat_at?->toIso8601String(), 'camera_state' => $state?->camera_state, 'preview' => (bool) ($online && $preview),
                'telemetry' => $state?->telemetry ?? (object) [], 'telemetry_at' => $state?->telemetry_at?->toIso8601String(),
                'counts_since' => $state?->counts_since?->toIso8601String(), 'scans' => $state?->scan_count,
                'valid' => $state?->valid_count, 'invalid' => $state?->invalid_count,
                'last_scan_at' => $state?->last_scan_at?->toIso8601String(), 'last_result' => $state?->last_result,
                'handoff_age_ms' => $state?->last_handoff_age_ms],
                'events' => $events->map(fn ($event) => ['kind' => $event->kind,
                    'details' => json_decode($event->details ?? '{}', true),
                    'at' => Carbon::parse($event->recorded_at, 'UTC')->toIso8601String()])->all(),
                'pagination' => ['page' => $events->currentPage(), 'last_page' => $events->lastPage(), 'total' => $events->total()],
                'observed_at' => now('UTC')->toIso8601String()];
        });
    }
}
