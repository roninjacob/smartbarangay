<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\IotDevice;
use App\Models\IotScanEvent;
use App\Models\IotScannerPairing;
use App\Models\QrTicket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IotScannerSessions
{
    public const BINDING_KEY = 'iot_scanner_binding';

    public function browserReady(): void
    {
        abort_unless(config('iot.enabled') && config('session.driver') === 'database', 503);
    }

    private function sessionHash(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }

    public function live(IotScannerPairing $pairing, IotDevice $device): bool
    {
        if ($device->revoked_at || $device->credential_version !== $pairing->credential_version
            || $pairing->expires_at->lte(now()) || $pairing->last_browser_seen_at->lt(now()->subSeconds(15))
            || (! $pairing->paired_at && $pairing->challenge_expires_at->lte(now()))
            || config('session.driver') !== 'database') {
            return false;
        }
        $admin = User::find($pairing->admin_id);
        if (! $admin || $admin->role !== UserRole::Admin || ! $admin->is_active || ! $admin->hasVerifiedEmail()
            || ! hash_equals($pairing->auth_hash, hash('sha256', $admin->getAuthPassword()))) {
            return false;
        }
        $session = DB::connection(config('session.connection'))->table(config('session.table'))
            ->where('id', $pairing->session_id)->first();

        return $session && (int) $session->user_id === $pairing->admin_id
            && $session->last_activity >= now()->subMinutes(config('session.lifetime'))->timestamp;
    }

    public function owns(IotScannerPairing $pairing, Request $request): bool
    {
        $binding = $request->session()->get(self::BINDING_KEY);

        return $pairing->admin_id === $request->user()->id && hash_equals($pairing->session_hash, $this->sessionHash($request))
            && is_string($binding) && hash_equals($pairing->binding_hash, hash('sha256', $binding));
    }

    public function devices(Request $request): array
    {
        $this->browserReady();

        return IotDevice::whereNull('revoked_at')->get()->map(function ($device) use ($request) {
            $pairing = IotScannerPairing::find($device->id);

            return ['id' => $device->id, 'name' => $device->name,
                'available' => ! $pairing || ! $this->live($pairing, $device) || $this->owns($pairing, $request)];
        })->all();
    }

    public function challenge(Request $request, string $deviceId): array
    {
        $this->browserReady();
        $challenge = 'SBP1:'.bin2hex(random_bytes(32));
        $binding = $request->session()->get(self::BINDING_KEY);
        if (! is_string($binding)) {
            $binding = bin2hex(random_bytes(32));
            $request->session()->put(self::BINDING_KEY, $binding);
        }

        return DB::transaction(function () use ($request, $deviceId, $challenge, $binding) {
            $device = IotDevice::lockForUpdate()->find($deviceId);
            abort_unless($device && ! $device->revoked_at, 409);
            $admin = app(AccountEligibility::class)->lock($request->user(), UserRole::Admin);
            $current = IotScannerPairing::lockForUpdate()->find($deviceId);
            abort_if($current && $this->live($current, $device) && ! $this->owns($current, $request), 409);
            // A new challenge atomically replaces this session's old lease and pending events.
            IotScannerPairing::where('session_hash', $this->sessionHash($request))->delete();
            $current?->delete();
            $pairing = new IotScannerPairing;
            $pairing->forceFill(['device_id' => $deviceId, 'id' => (string) Str::uuid(), 'admin_id' => $admin->id,
                'session_id' => $request->session()->getId(), 'session_hash' => $this->sessionHash($request),
                'binding_hash' => hash('sha256', $binding), 'auth_hash' => hash('sha256', $admin->getAuthPassword()),
                'credential_version' => $device->credential_version, 'challenge_hash' => hash('sha256', $challenge),
                'challenge_expires_at' => now('UTC')->addSeconds(90), 'expires_at' => now('UTC')->addMinutes(10),
                'last_browser_seen_at' => now('UTC')])->save();

            return ['challenge' => $challenge, 'challenge_expires_at' => $pairing->challenge_expires_at->toIso8601String()];
        }, 3);
    }

    private function authenticatedPairing(Request $request): array
    {
        $device = IotDevice::lockForUpdate()->find($request->attributes->get('iot_device_id'));
        abort_unless($device && ! $device->revoked_at && $device->credential_version === (int) $request->header('X-SB-Key-Version'), 401);
        $owner = IotScannerPairing::find($device->id);
        if ($owner) {
            User::lockForUpdate()->find($owner->admin_id);
        }
        $pairing = IotScannerPairing::lockForUpdate()->find($device->id);
        abort_unless($pairing && $this->live($pairing, $device), 409);

        return [$device, $pairing];
    }

    public function pair(Request $request, array $body): array
    {
        return DB::transaction(function () use ($request, $body) {
            [, $pairing] = $this->authenticatedPairing($request);
            abort_unless(! $pairing->paired_at && $pairing->challenge_hash
                && hash_equals($pairing->challenge_hash, hash('sha256', $body['challenge'])), 409);
            $pairing->forceFill(['paired_at' => now('UTC'), 'challenge_hash' => null, 'boot_id' => $body['boot_id']])->save();

            return ['ok' => true, 'pairing_id' => $pairing->id, 'expires_at' => $pairing->expires_at->toIso8601String()];
        }, 3);
    }

    public function scan(Request $request, array $body): array
    {
        return DB::transaction(function () use ($request, $body) {
            [, $pairing] = $this->authenticatedPairing($request);
            abort_unless($pairing->paired_at && $pairing->id === $body['pairing_id'] && $pairing->boot_id === $body['boot_id'], 409);
            $age = (int) now()->getTimestampMs() - $body['captured_at_ms'];
            abort_unless($age >= 0 && $age < 5000, 422);
            abort_if($body['scanner_session'] < $pairing->last_scan_session
                || ($body['scanner_session'] === $pairing->last_scan_session && $body['sequence'] <= $pairing->last_sequence)
                || $body['captured_at_ms'] < $pairing->last_captured_at_ms, 409);
            $result = app(QrTicketVerifier::class)->verify($body['payload']);
            abort_if((int) now()->getTimestampMs() - $body['captured_at_ms'] >= 5000, 422);
            $event = new IotScanEvent;
            $event->forceFill(['id' => (string) Str::uuid(), 'pairing_id' => $pairing->id, 'boot_id' => $body['boot_id'],
                'scanner_session' => $body['scanner_session'], 'sequence' => $body['sequence'],
                'captured_at_ms' => $body['captured_at_ms'], 'expires_at' => Carbon::createFromTimestampMsUTC($body['captured_at_ms'])->addSeconds(5),
                'payload' => $body['payload']])->save();
            $pairing->forceFill(['last_scan_session' => $body['scanner_session'], 'last_sequence' => $body['sequence'],
                'last_captured_at_ms' => $body['captured_at_ms']])->save();

            return ['ok' => true, 'event_id' => $event->id, 'valid' => $result['valid']];
        }, 3);
    }

    public function poll(Request $request): array
    {
        $automatic = IotScannerPairing::where('session_hash', $this->sessionHash($request))->first();
        if ($automatic?->automatic) {
            return app(IotAutomaticScanner::class)->poll($request);
        }
        $this->browserReady();

        return DB::transaction(function () use ($request) {
            // Device -> account -> pairing is the same lock order as challenge/device requests.
            $candidate = IotScannerPairing::where('session_hash', $this->sessionHash($request))->first();
            $device = $candidate ? IotDevice::lockForUpdate()->find($candidate->device_id) : null;
            app(AccountEligibility::class)->lock($request->user(), UserRole::Admin);
            $pairing = IotScannerPairing::where('session_hash', $this->sessionHash($request))->lockForUpdate()->first();
            if (! $pairing) {
                return ['state' => 'unpaired', 'result' => null];
            }
            if (! $this->owns($pairing, $request) || ! $device || $device->id !== $pairing->device_id || ! $this->live($pairing, $device)) {
                $pairing->delete();

                return ['state' => 'expired', 'result' => null];
            }
            $pairing->forceFill(['last_browser_seen_at' => now('UTC')])->save();
            $result = null;
            if ($pairing->paired_at) {
                $event = IotScanEvent::where('pairing_id', $pairing->id)->whereNull('consumed_at')
                    ->where('captured_at_ms', '>', (int) now()->getTimestampMs() - 5000)
                    ->where('captured_at_ms', '<=', (int) now()->getTimestampMs())
                    ->orderBy('captured_at_ms')->orderBy('id')->lockForUpdate()->first();
                if ($event) {
                    // CAS remains authoritative even on SQLite, whose FOR UPDATE is a no-op.
                    $claimed = IotScanEvent::whereKey($event->id)->whereNull('consumed_at')->update(['consumed_at' => now('UTC'), 'payload' => null]);
                    if ($claimed === 1) {
                        $request->session()->forget(CheckInReservations::SESSION_KEY);
                        $evidence = null;
                        $result = app(QrTicketVerifier::class)->verify($event->payload, function (QrTicket $ticket) use ($request, &$evidence) {
                            $evidence = app(CheckInReservations::class)->evidence($ticket, $request->user());
                        });
                        if ((int) now()->getTimestampMs() - $event->captured_at_ms >= 5000) {
                            $result = null;
                        } elseif ($evidence) {
                            $request->session()->put(CheckInReservations::SESSION_KEY, $evidence);
                        }
                    }
                }
            }

            return ['state' => $pairing->paired_at ? 'connected' : 'waiting', 'device' => $device->name,
                'expires_at' => ($pairing->paired_at ? $pairing->expires_at : $pairing->challenge_expires_at)->toIso8601String(), 'result' => $result];
        }, 3);
    }

    public function disconnect(Request $request): void
    {
        $this->browserReady();
        DB::transaction(function () use ($request) {
            $pairing = IotScannerPairing::where('session_hash', $this->sessionHash($request))->lockForUpdate()->first();
            if ($pairing && $this->owns($pairing, $request)) {
                app(IotAutomaticScanner::class)->deleteFrame($pairing->device_id);
                $pairing->delete();
            }
        });
        $request->session()->forget([self::BINDING_KEY, CheckInReservations::SESSION_KEY]);
    }
}
