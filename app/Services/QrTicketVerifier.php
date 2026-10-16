<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\QrTicket;

class QrTicketVerifier
{
    public function verify(mixed $payload, ?\Closure $onVerified = null): array
    {
        $invalid = ['valid' => false, 'message' => 'Unknown or invalid QR ticket.'];
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
            return ['valid' => false, 'message' => 'This QR ticket has already completed its transaction.'];
        }
        if (! $reservation->status->canReceiveQrTicket()) {
            return ['valid' => false, 'message' => 'This reservation is not eligible for QR verification.'];
        }
        if (! $reservation->user->is_active || ! $reservation->user->hasVerifiedEmail()) {
            return $invalid;
        }

        $onVerified?->__invoke($ticket);

        return ['valid' => true, 'message' => 'Valid QR Ticket',
            'reservation_url' => route('admin.reservations.show', $reservation), 'reservation' => [
                'resident' => $reservation->user->name,
                'service' => $reservation->service->name,
                'schedule' => $reservation->schedule->date->format('F j, Y').' · '.
                    substr($reservation->schedule->start_time, 0, 5).'–'.substr($reservation->schedule->end_time, 0, 5),
                'status' => $reservation->status->label(),
            ]];
    }
}
