<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\IotDevice;
use App\Models\IotDeviceScanEvent;
use App\Models\IotDeviceState;
use App\Models\IotScannerPairing;
use App\Models\QrTicket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IotAutomaticScanner
{
    public const FRAME_BYTES = 32768;

    private function device(Request $request): IotDevice
    {
        $device = IotDevice::lockForUpdate()->find($request->attributes->get('iot_device_id'));
        abort_unless($device && ! $device->revoked_at && $device->credential_version === (int) $request->header('X-SB-Key-Version'), 401);

        return $device;
    }

    private function fresh(int $captured): void
    {
        $age = (int) now()->getTimestampMs() - $captured;
        abort_unless($age >= 0 && $age < 5000, 422);
    }

    private function state(IotDevice $device, string $boot): IotDeviceState
    {
        $state = IotDeviceState::find($device->id);
        if (! $state) {
            $state = new IotDeviceState;
            $state->forceFill(['device_id' => $device->id, 'boot_id' => $boot, 'last_seen_at' => now('UTC')->startOfSecond()])->save();
        }

        return $state;
    }

    public function status(Request $request, array $body): array
    {
        return DB::transaction(function () use ($request, $body) {
            $device = $this->device($request);
            $this->fresh($body['captured_at_ms']);
            $state = $this->state($device, $body['boot_id']);
            // An older delayed heartbeat cannot roll a newer boot backwards.
            abort_if($body['captured_at_ms'] < $state->last_status_capture, 409);
            app(IotTelemetry::class)->heartbeat($state, $body);
            if ($state->boot_id !== $body['boot_id']) {
                $this->deleteFrame($device->id);
                $state->forceFill(['boot_id' => $body['boot_id'], 'last_session' => 0, 'last_sequence' => 0,
                    'last_capture' => 0, 'frame_at' => null, 'frame_sequence' => 0, 'frame_lease' => null, 'frame_captured_ms' => null]);
            }
            $state->forceFill(['last_seen_at' => now('UTC'), 'camera_state' => $body['camera_state'],
                'last_status_capture' => $body['captured_at_ms'], 'heartbeat_at' => now('UTC')])->save();
            $lease = IotScannerPairing::find($device->id);
            $preview = $lease && $lease->automatic && $lease->preview_until?->isFuture()
                && app(IotScannerSessions::class)->live($lease, $device);

            return ['ok' => true, 'preview' => (bool) $preview, 'lease_id' => $preview ? $lease->id : null,
                'preview_expires_at' => $preview ? $lease->preview_until->toIso8601String() : null];
        }, 3);
    }

    public function scan(Request $request, array $body): array
    {
        return DB::transaction(function () use ($request, $body) {
            $device = $this->device($request);
            $this->fresh($body['captured_at_ms']);
            $state = $this->state($device, $body['boot_id']);
            abort_unless($state->boot_id === $body['boot_id'], 409);
            abort_if($body['scanner_session'] < $state->last_session
                || ($body['scanner_session'] === (int) $state->last_session && $body['sequence'] <= $state->last_sequence)
                || $body['captured_at_ms'] < $state->last_capture, 409);
            abort_if(IotDeviceScanEvent::where('device_id', $device->id)->where('boot_id', $body['boot_id'])
                ->where('scanner_session', $body['scanner_session'])->where('sequence', $body['sequence'])->exists(), 409);
            $hash = hash('sha256', $body['payload']);
            abort_if($state->last_payload_hash === $hash && $state->last_scan_at?->gt(now()->subSeconds(3)), 409);
            $result = app(QrTicketVerifier::class)->verify($body['payload']);
            $this->fresh($body['captured_at_ms']);
            $event = new IotDeviceScanEvent;
            $event->forceFill(['id' => (string) Str::uuid(), 'device_id' => $device->id, 'boot_id' => $body['boot_id'],
                'scanner_session' => $body['scanner_session'], 'sequence' => $body['sequence'],
                'captured_at_ms' => $body['captured_at_ms'], 'payload' => $body['payload'],
                'expires_at' => Carbon::createFromTimestampMsUTC($body['captured_at_ms'])->addSeconds(5)])->save();
            app(IotTelemetry::class)->scan($state, $result['valid']);
            $state->forceFill(['last_seen_at' => now('UTC'), 'last_session' => $body['scanner_session'],
                'last_sequence' => $body['sequence'], 'last_capture' => $body['captured_at_ms'],
                'last_payload_hash' => $hash, 'last_scan_at' => now('UTC'), 'last_result' => $result['valid'] ? 'valid' : 'invalid'])->save();

            return ['ok' => true, 'event_id' => $event->id, 'valid' => $result['valid']];
        }, 3);
    }

    public function connect(Request $request, string $id, bool $preview = false): array
    {
        return DB::transaction(function () use ($request, $id, $preview) {
            // Reuse the existing exclusive, password/session-bound Admin lease.
            app(IotScannerSessions::class)->challenge($request, $id);
            IotScannerPairing::findOrFail($id)->forceFill(['automatic' => true, 'paired_at' => now('UTC'),
                'challenge_hash' => null, 'preview_until' => $preview ? now('UTC')->addSeconds(10) : null, 'monitor_started_ms' => now()->getTimestampMs()])->save();

            return ['state' => 'connected', 'preview' => $preview];
        }, 3);
    }

    private function browserLease(Request $request): array
    {
        $sessions = app(IotScannerSessions::class);
        $sessions->browserReady();
        $candidate = IotScannerPairing::where('session_hash', hash('sha256', $request->session()->getId()))->first();
        $device = $candidate ? IotDevice::lockForUpdate()->find($candidate->device_id) : null;
        app(AccountEligibility::class)->lock($request->user(), UserRole::Admin);
        $lease = $candidate ? IotScannerPairing::lockForUpdate()->find($candidate->device_id) : null;
        abort_unless($device && $lease && $lease->automatic && $sessions->owns($lease, $request) && $sessions->live($lease, $device), 409);

        return [$device, $lease];
    }

    public function poll(Request $request): array
    {
        return DB::transaction(function () use ($request) {
            [$device, $lease] = $this->browserLease($request);
            $lease->last_browser_seen_at = now('UTC');
            if ($lease->preview_until?->isFuture()) {
                $lease->preview_until = now('UTC')->addSeconds(10)->min($lease->expires_at);
            }
            $lease->save();
            $state = IotDeviceState::find($device->id);
            $event = IotDeviceScanEvent::where('device_id', $device->id)->whereNull('consumed_at')
                ->where('captured_at_ms', '>=', $lease->monitor_started_ms)
                ->where('captured_at_ms', '>', now()->getTimestampMs() - 5000)
                ->where('captured_at_ms', '<=', now()->getTimestampMs())
                ->orderBy('captured_at_ms')->orderBy('id')->lockForUpdate()->first();
            $result = null;
            if ($event && IotDeviceScanEvent::whereKey($event->id)->whereNull('consumed_at')->update(['consumed_at' => now('UTC'), 'payload' => null]) === 1) {
                $request->session()->forget(CheckInReservations::SESSION_KEY);
                $evidence = null;
                $result = app(QrTicketVerifier::class)->verify($event->payload, function (QrTicket $ticket) use ($request, &$evidence) {
                    $evidence = app(CheckInReservations::class)->evidence($ticket, $request->user());
                });
                if (now()->getTimestampMs() - $event->captured_at_ms >= 5000) {
                    $result = null;
                } elseif ($evidence) {
                    $state?->forceFill(['last_handoff_age_ms' => now()->getTimestampMs() - $event->captured_at_ms])->save();
                    $request->session()->put(CheckInReservations::SESSION_KEY, $evidence);
                }
            }

            return ['state' => 'connected', 'device' => $device->name, 'expires_at' => $lease->expires_at->toIso8601String(),
                'online' => (bool) $state?->heartbeat_at?->gt(now()->subSeconds(15)),
                'last_seen_at' => $state?->last_seen_at->toIso8601String(), 'camera_state' => $state?->camera_state,
                'last_scan_at' => $state?->last_scan_at?->toIso8601String(), 'last_result' => $state?->last_result,
                'preview' => (bool) $lease->preview_until?->isFuture(), 'result' => $result];
        }, 3);
    }

    public function preview(Request $request, bool $enabled): array
    {
        return DB::transaction(function () use ($request, $enabled) {
            [$device, $lease] = $this->browserLease($request);
            $lease->forceFill(['preview_until' => $enabled ? now('UTC')->addSeconds(10)->min($lease->expires_at) : null])->save();
            if (! $enabled) {
                $this->deleteFrame($device->id);
            }

            return ['preview' => $enabled];
        }, 3);
    }

    public function upload(Request $request, array $body): array
    {
        $jpeg = base64_decode($body['image'], true);
        abort_unless(is_string($jpeg) && strlen($jpeg) <= self::FRAME_BYTES, 422);
        $size = @getimagesizefromstring($jpeg);
        abort_unless($size && $size[2] === IMAGETYPE_JPEG && $size[0] <= 640 && $size[1] <= 480, 422);
        // Re-encode bounded pixels to strip EXIF/extra segments; only low-resolution output survives.
        abort_unless(function_exists('imagecreatefromstring'), 503);
        $image = @imagecreatefromstring($jpeg);
        abort_unless($image, 422);
        $small = imagescale($image, min(320, $size[0]));
        imagedestroy($image);
        abort_unless($small, 422);
        ob_start();
        imagejpeg($small, null, 55);
        $clean = ob_get_clean();
        imagedestroy($small);
        abort_unless(is_string($clean) && strlen($clean) <= self::FRAME_BYTES, 422);

        return DB::transaction(function () use ($request, $body, $clean) {
            $device = $this->device($request);
            $this->fresh($body['captured_at_ms']);
            $lease = IotScannerPairing::find($device->id);
            if ($lease) {
                User::lockForUpdate()->find($lease->admin_id);
                $lease = IotScannerPairing::lockForUpdate()->find($device->id);
            }
            abort_unless($lease && $lease->automatic && $lease->id === $body['lease_id'] && $lease->preview_until?->isFuture()
                && app(IotScannerSessions::class)->live($lease, $device), 409);
            $state = IotDeviceState::find($device->id);
            abort_unless($state && $state->boot_id === $body['boot_id'], 409);
            if ($state->frame_lease === $lease->id) {
                abort_if($body['sequence'] <= $state->frame_sequence || $state->frame_at?->gt(now()->subSecond()), 409);
            }
            abort_unless(Storage::disk('local')->put($this->framePath($device->id), Crypt::encryptString(base64_encode($clean))), 503);
            $state->forceFill(['frame_at' => now('UTC'), 'frame_captured_ms' => $body['captured_at_ms'], 'frame_sequence' => $body['sequence'], 'frame_lease' => $lease->id,
                'last_seen_at' => now('UTC')])->save();

            return ['ok' => true];
        }, 3);
    }

    public function frame(Request $request): string
    {
        return $this->frameSnapshot($request)['jpeg'];
    }

    public function frameSnapshot(Request $request): array
    {
        return DB::transaction(function () use ($request) {
            [$device, $lease] = $this->browserLease($request);
            $state = IotDeviceState::find($device->id);
            abort_unless($lease->preview_until?->isFuture() && $state?->frame_lease === $lease->id
                && $state->frame_at?->gt(now()->subSeconds(5)) && Storage::disk('local')->exists($this->framePath($device->id)), 404);
            $age = now()->getTimestampMs() - ($state->frame_captured_ms ?? 0);
            abort_unless($age >= 0 && $age < 5000, 404);

            return ['jpeg' => base64_decode(Crypt::decryptString(Storage::disk('local')->get($this->framePath($device->id))), true),
                'age_ms' => max($age, now()->getTimestampMs() - $state->frame_at->getTimestampMs()),
                'sequence' => $state->frame_sequence];
        });
    }

    private function framePath(string $id): string
    {
        $root = strtolower(str_replace('\\', '/', Storage::disk('local')->path('')));
        $public = strtolower(rtrim(str_replace('\\', '/', public_path()), '/').'/');
        abort_if(str_starts_with(rtrim($root, '/').'/', $public), 503);

        return 'iot-preview/'.$id.'.frame';
    }

    public function deleteFrame(string $id): void
    {
        Storage::disk('local')->delete($this->framePath($id));
    }

    public function prune(): void
    {
        IotDeviceScanEvent::where('expires_at', '<=', now('UTC'))->delete();
        IotDeviceState::whereNotNull('frame_at')->where('frame_at', '<=', now('UTC')->subSeconds(5))
            ->eachById(function ($candidate) {
                DB::transaction(function () use ($candidate) {
                    IotDevice::lockForUpdate()->find($candidate->device_id);
                    $state = IotDeviceState::find($candidate->device_id);
                    if ($state?->frame_at?->lte(now('UTC')->subSeconds(5))) {
                        $this->deleteFrame($candidate->device_id);
                    }
                });
            }, 100, 'device_id');
    }
}
