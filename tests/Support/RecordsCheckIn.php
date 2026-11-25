<?php

namespace Tests\Support;

use App\Enums\ReservationStatus;
use App\Models\CheckinLog;
use App\Models\Reservation;

/**
 * Test fixture: puts a reservation in the state the certificate gate requires
 * (Ready for Pickup with a recorded check-in). The real check-in flow
 * (scan evidence + CheckInReservations::record) is covered by its own tests.
 */
trait RecordsCheckIn
{
    protected function recordCheckIn(Reservation $reservation, ?int $adminId = null): Reservation
    {
        if ($reservation->status !== ReservationStatus::ReadyForPickup) {
            $reservation->forceFill(['status' => ReservationStatus::ReadyForPickup])->save();
        }
        if (! $reservation->checkinLogs()->where('verification_result', CheckinLog::ARRIVAL)->exists()) {
            $reservation->checkinLogs()->create(['verification_result' => CheckinLog::ARRIVAL,
                'verified_at' => now('UTC'), 'verified_by' => $adminId ?? auth()->id()]);
        }

        return $reservation->refresh();
    }
}
