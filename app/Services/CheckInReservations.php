<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\CheckinLog;
use App\Models\QrTicket;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckInReservations
{
    public const SESSION_KEY = 'qr_checkin_evidence';

    public function evidence(QrTicket $ticket, User $admin): array
    {
        return ['admin_id' => $admin->id, 'reservation_id' => $ticket->reservation_id, 'ticket_id' => $ticket->id,
            'credential_hash' => hash('sha256', $ticket->encodedPayload()), 'expires' => now()->addMinutes(5)->timestamp];
    }

    public function hasEvidence(mixed $evidence, User $admin, Reservation $reservation): bool
    {
        return is_array($evidence) && ($evidence['admin_id'] ?? null) === $admin->id
            && ($evidence['reservation_id'] ?? null) === $reservation->id
            && is_int($evidence['expires'] ?? null) && $evidence['expires'] > now()->timestamp;
    }

    public function record(Reservation $reservation, User $admin, mixed $evidence): CheckinLog
    {
        abort_unless($admin->role === UserRole::Admin && $admin->is_active && $admin->hasVerifiedEmail(), 403);
        try {
            return DB::transaction(function () use ($reservation, $admin, $evidence) {
                $admin = app(AccountEligibility::class)->lock($admin, UserRole::Admin);
                $locked = Reservation::lockForUpdate()->findOrFail($reservation->id);
                if ($locked->status !== ReservationStatus::ReadyForPickup) {
                    $this->reject('Check-in is available only when this reservation is Ready for Pickup.');
                }
                if (! $this->hasEvidence($evidence, $admin, $locked)) {
                    $this->reject('Scan and verify this QR ticket again before confirming check-in. Verification expires after five minutes.');
                }
                $ticket = $locked->qrTicket()->lockForUpdate()->first();
                if (! $ticket || ($evidence['ticket_id'] ?? null) !== $ticket->id
                    || ! is_string($evidence['credential_hash'] ?? null)
                    || ! hash_equals($evidence['credential_hash'], hash('sha256', $ticket->encodedPayload()))
                    || ! app(QrTicketVerifier::class)->verify($ticket->encodedPayload())['valid']) {
                    $this->reject('This QR ticket is no longer valid for check-in. Scan it again or review the reservation.');
                }
                $existing = $locked->checkinLogs()->where('verification_result', CheckinLog::ARRIVAL)->first();
                if ($existing) {
                    return $existing;
                }

                return $locked->checkinLogs()->create(['verification_result' => CheckinLog::ARRIVAL,
                    'verified_at' => now('UTC'), 'verified_by' => $admin->id]);
            });
        } catch (UniqueConstraintViolationException) {
            return $reservation->checkinLogs()->where('verification_result', CheckinLog::ARRIVAL)->sole();
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['checkin' => $message]);
    }
}
