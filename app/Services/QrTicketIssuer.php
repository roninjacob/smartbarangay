<?php

namespace App\Services;

use App\Models\QrTicket;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QrTicketIssuer
{
    public function issue(Reservation $reservation): ?QrTicket
    {
        // All issuers serialize on the reservation row, including approval and backfill.
        // Nested transactions remain part of the Admin approval/history transaction.
        return DB::transaction(function () use ($reservation) {
            $locked = Reservation::lockForUpdate()->findOrFail($reservation->id);
            if (! $locked->status->canReceiveQrTicket()) {
                return null;
            }

            $existing = $locked->qrTicket()->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            // UUID v4 uses cryptographically secure random bytes and fits the Phase 1 schema.
            $credential = (string) Str::uuid();

            return $locked->qrTicket()->create([
                'ticket_code' => $credential,
                'qr_payload' => $credential,
                'generated_at' => now(),
            ]);
        });
    }
}
