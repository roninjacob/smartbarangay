<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\CheckinLog;
use App\Models\QrTicket;

class QrTicketVerifier
{
    // Transaction outcomes. Verification never performs them; check-in stays a separate Admin action.
    public const CHECK_IN_AUTHORIZED = 'check_in_authorized';

    public const CHECKED_IN = 'checked_in';

    public const NOT_READY = 'not_ready';

    public const DENIED = 'denied';

    public function verify(mixed $payload, ?\Closure $onVerified = null): array
    {
        $invalid = ['valid' => false, 'outcome' => self::DENIED, 'reason' => 'unknown', 'message' => 'Unknown or invalid QR ticket.'];
        if (! is_string($payload) || strlen($payload) !== 41 || ! preg_match('/\ASBQ1:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $payload)) {
            return $invalid;
        }

        // The credential resolves its own reservation. Never accept a client-supplied owner or reservation ID.
        $ticket = QrTicket::with(['reservation.user', 'reservation.service', 'reservation.schedule'])
            ->where('qr_payload', substr($payload, 5))->first();
        $reservation = $ticket?->reservation;
        if (! $reservation || ! hash_equals($ticket->ticket_code, $ticket->qr_payload)
            || ! $reservation->user || ! $reservation->service || ! $reservation->schedule
            || $reservation->user->role !== UserRole::Resident) {
            return $invalid;
        }
        // Completed legacy tickets stay unusable even if their historical usage metadata is absent.
        if ($ticket->isUsed() || $reservation->status === ReservationStatus::Completed) {
            return ['valid' => false, 'outcome' => self::DENIED, 'reason' => 'completed',
                'message' => 'This QR ticket has already completed its transaction.'];
        }
        if (! $reservation->status->canReceiveQrTicket()) {
            return ['valid' => false, 'outcome' => self::DENIED, 'reason' => 'not_eligible',
                'message' => 'This reservation is not eligible for QR verification.'];
        }
        if (! $reservation->user->is_active || ! $reservation->user->hasVerifiedEmail()) {
            return $invalid;
        }

        $onVerified?->__invoke($ticket);

        // Describe the transaction the ticket permits right now; nothing here changes it.
        $arrival = $reservation->checkinLogs()->where('verification_result', CheckinLog::ARRIVAL)->first();
        $outcome = $reservation->status !== ReservationStatus::ReadyForPickup ? self::NOT_READY
            : ($arrival ? self::CHECKED_IN : self::CHECK_IN_AUTHORIZED);

        return ['valid' => true, 'outcome' => $outcome, 'message' => 'Valid QR Ticket',
            'reservation_url' => route('admin.reservations.show', $reservation),
            'check_in_url' => $outcome === self::CHECK_IN_AUTHORIZED ? route('admin.reservations.check-in.store', $reservation) : null,
            'reservation' => [
                'reference' => '#'.$reservation->id,
                'resident' => $reservation->user->name,
                'service' => $reservation->service->name,
                'schedule' => $reservation->appointment->date->format('F j, Y').' · '.
                    substr($reservation->appointment->start_time, 0, 5).'–'.substr($reservation->appointment->end_time, 0, 5),
                'status' => $reservation->status->label(),
                'checked_in_at' => $arrival?->verified_at?->timezone('Asia/Manila')->format('F j, Y, g:i A'),
            ]];
    }
}
