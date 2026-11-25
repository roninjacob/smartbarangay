<?php

namespace App\Services;

use App\Models\IotDevice;
use App\Models\IotDeviceCommand;
use App\Models\IotDeviceState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Software scanner control: enable/disable and restart.
 *
 * Desired state is the newest set_enabled command (durable, default enabled). Actual state is
 * what the scanner reports in its signed status heartbeat (telemetry control_revision and
 * scanner_enabled). Commands reach the scanner in the status response; nothing is executed on
 * the server's word alone. Every mutation holds the device row lock, which serializes Admin
 * requests, device heartbeats and reconciliation for one scanner.
 */
class IotDeviceControl
{
    public const RESTART_DELIVERY_SECONDS = 60;

    // After the scanner acknowledges a restart, a new boot must be seen within this time.
    public const RESTART_COMPLETION_SECONDS = 90;

    public function desired(string $device): array
    {
        $latest = IotDeviceCommand::where('device_id', $device)->where('type', IotDeviceCommand::SET_ENABLED)
            ->orderByDesc('revision')->first();

        return ['revision' => $latest?->revision ?? 0, 'enabled' => $latest?->enabled ?? true];
    }

    private function nextRevision(string $device): int
    {
        return (int) IotDeviceCommand::where('device_id', $device)->max('revision') + 1;
    }

    private function create(IotDevice $device, User $admin, string $type, ?bool $enabled, ?\DateTimeInterface $expires): IotDeviceCommand
    {
        $command = new IotDeviceCommand;
        $command->forceFill(['id' => (string) Str::uuid(), 'device_id' => $device->id, 'revision' => $this->nextRevision($device->id),
            'type' => $type, 'enabled' => $enabled, 'status' => IotDeviceCommand::PENDING, 'admin_id' => $admin->id,
            'expires_at' => $expires])->save();
        app(IotTelemetry::class)->record($device->id, 'control_requested',
            ['action' => $type === IotDeviceCommand::RESTART ? 'restart' : ($enabled ? 'enable' : 'disable'), 'revision' => $command->revision]);

        return $command;
    }

    /** Request scanner ON or OFF. Asking for the state already desired creates nothing new. */
    public function setEnabled(string $deviceId, User $admin, bool $enabled): IotDeviceCommand
    {
        return DB::transaction(function () use ($deviceId, $admin, $enabled) {
            $device = IotDevice::lockForUpdate()->findOrFail($deviceId);
            abort_if($device->revoked_at, 409);
            $latest = IotDeviceCommand::where('device_id', $device->id)->where('type', IotDeviceCommand::SET_ENABLED)
                ->orderByDesc('revision')->first();
            if ($latest && $latest->enabled === $enabled) {
                return $latest;
            }
            // A newer desired state replaces any older one that was not applied yet.
            IotDeviceCommand::where('device_id', $device->id)->where('type', IotDeviceCommand::SET_ENABLED)
                ->whereIn('status', [IotDeviceCommand::PENDING, IotDeviceCommand::DELIVERED])
                ->update(['status' => IotDeviceCommand::SUPERSEDED, 'completed_at' => now('UTC')]);

            return $this->create($device, $admin, IotDeviceCommand::SET_ENABLED, $enabled, null);
        }, 3);
    }

    /** Request one restart. While a restart is open, repeating the request returns it unchanged. */
    public function restart(string $deviceId, User $admin): IotDeviceCommand
    {
        return DB::transaction(function () use ($deviceId, $admin) {
            $device = IotDevice::lockForUpdate()->findOrFail($deviceId);
            abort_if($device->revoked_at, 409);
            $this->expire($device->id);
            $open = IotDeviceCommand::where('device_id', $device->id)->where('type', IotDeviceCommand::RESTART)
                ->whereIn('status', IotDeviceCommand::OPEN)->orderByDesc('revision')->first();

            return $open ?? $this->create($device, $admin, IotDeviceCommand::RESTART, null,
                now('UTC')->addSeconds(self::RESTART_DELIVERY_SECONDS));
        }, 3);
    }

    /** Close restarts that were never delivered, or never followed by a new boot. */
    private function expire(string $device): void
    {
        IotDeviceCommand::where('device_id', $device)->where('type', IotDeviceCommand::RESTART)
            ->whereIn('status', [IotDeviceCommand::PENDING, IotDeviceCommand::DELIVERED])->where('expires_at', '<=', now('UTC'))
            ->update(['status' => IotDeviceCommand::EXPIRED, 'result' => 'not_acknowledged', 'completed_at' => now('UTC')]);
        IotDeviceCommand::where('device_id', $device)->where('type', IotDeviceCommand::RESTART)
            ->where('status', IotDeviceCommand::RESTARTING)->where('acknowledged_at', '<=', now('UTC')->subSeconds(self::RESTART_COMPLETION_SECONDS))
            ->update(['status' => IotDeviceCommand::FAILED, 'result' => 'no_new_boot_observed', 'completed_at' => now('UTC')]);
    }

    /**
     * Reconcile with a signed heartbeat and return what the scanner should apply. Called by the
     * status endpoint while it holds the device lock. $previousBoot is the boot before this heartbeat.
     */
    public function heartbeat(IotDevice $device, ?string $previousBoot, string $boot, array $telemetry): array
    {
        $this->expire($device->id);
        $now = now('UTC');
        if (array_key_exists('control_revision', $telemetry)) {
            $reported = (int) $telemetry['control_revision'];
            $enabled = ($telemetry['scanner_enabled'] ?? 1) === 1;
            IotDeviceCommand::where('device_id', $device->id)->where('type', IotDeviceCommand::SET_ENABLED)
                ->whereIn('status', [IotDeviceCommand::PENDING, IotDeviceCommand::DELIVERED])->where('revision', '<=', $reported)
                ->get()->each(function (IotDeviceCommand $command) use ($reported, $enabled, $now, $device) {
                    $applied = $command->revision === $reported && $command->enabled === $enabled;
                    $command->forceFill(['status' => $applied ? IotDeviceCommand::APPLIED : IotDeviceCommand::SUPERSEDED,
                        'acknowledged_at' => $now, 'completed_at' => $now])->save();
                    if ($applied) {
                        app(IotTelemetry::class)->record($device->id, $enabled ? 'scanner_enabled' : 'scanner_disabled', ['revision' => $command->revision]);
                    }
                });
        }
        if (array_key_exists('restart_revision', $telemetry)) {
            IotDeviceCommand::where('device_id', $device->id)->where('type', IotDeviceCommand::RESTART)
                ->whereIn('status', [IotDeviceCommand::PENDING, IotDeviceCommand::DELIVERED])->where('revision', (int) $telemetry['restart_revision'])
                ->update(['status' => IotDeviceCommand::RESTARTING, 'acknowledged_at' => $now, 'boot_id' => $boot]);
        }
        // A restart is complete only when a different boot reports in after the acknowledgment.
        IotDeviceCommand::where('device_id', $device->id)->where('type', IotDeviceCommand::RESTART)
            ->where('status', IotDeviceCommand::RESTARTING)->where('boot_id', '!=', $boot)->get()
            ->each(function (IotDeviceCommand $command) use ($now, $device, $telemetry) {
                $command->forceFill(['status' => IotDeviceCommand::APPLIED, 'result' => 'new_boot_observed', 'completed_at' => $now])->save();
                app(IotTelemetry::class)->record($device->id, 'restart_confirmed', ['revision' => $command->revision, 'reset' => $telemetry['reset'] ?? null]);
            });

        $desired = $this->desired($device->id);
        $restart = IotDeviceCommand::where('device_id', $device->id)->where('type', IotDeviceCommand::RESTART)
            ->whereIn('status', [IotDeviceCommand::PENDING, IotDeviceCommand::DELIVERED])->orderByDesc('revision')->first();
        IotDeviceCommand::where('device_id', $device->id)->where('status', IotDeviceCommand::PENDING)
            ->where('revision', '<=', max($desired['revision'], $restart?->revision ?? 0))
            ->update(['status' => IotDeviceCommand::DELIVERED, 'delivered_at' => $now]);

        return ['revision' => $desired['revision'], 'enabled' => $desired['enabled'], 'restart' => $restart?->revision];
    }

    /** Dashboard view: desired versus acknowledged state, open commands and recent history. */
    public function report(string $device, ?IotDeviceState $state): array
    {
        $this->expire($device);
        $telemetry = $state?->telemetry ?? [];
        $supported = is_array($telemetry) && array_key_exists('control_revision', $telemetry);
        $desired = $this->desired($device);
        $commands = IotDeviceCommand::where('device_id', $device)->orderByDesc('revision')->limit(6)
            ->get(['id', 'revision', 'type', 'enabled', 'status', 'result', 'created_at', 'acknowledged_at', 'completed_at']);

        return [
            'supported' => $supported,
            'desired' => $desired,
            'actual' => $supported ? ['revision' => (int) $telemetry['control_revision'], 'enabled' => ($telemetry['scanner_enabled'] ?? 1) === 1] : null,
            'commands' => $commands->map(fn (IotDeviceCommand $command) => [
                'id' => $command->id, 'revision' => $command->revision, 'type' => $command->type, 'enabled' => $command->enabled,
                'status' => $command->status, 'result' => $command->result,
                'created_at' => $command->created_at?->toIso8601String(), 'completed_at' => $command->completed_at?->toIso8601String(),
                'latency_ms' => $command->acknowledged_at && $command->created_at
                    ? max(0, $command->acknowledged_at->getTimestampMs() - $command->created_at->getTimestampMs()) : null,
            ])->all(),
        ];
    }
}
