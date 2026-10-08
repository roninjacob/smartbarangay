<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\QrTicketIssuer;
use Illuminate\Console\Command;

class BackfillQrTickets extends Command
{
    protected $signature = 'smartbarangay:backfill-qr-tickets {--dry-run : Count eligible reservations without issuing tickets}';

    protected $description = 'Issue missing QR tickets for existing Approved / Ready for Pickup reservations';

    public function handle(QrTicketIssuer $tickets): int
    {
        $eligible = array_filter(ReservationStatus::cases(), fn ($status) => $status->canReceiveQrTicket());
        $query = Reservation::whereIn('status', $eligible)->whereDoesntHave('qrTicket');
        if ($this->option('dry-run')) {
            $this->info('Dry run: '.$query->count().' eligible reservation(s) without a QR ticket. No records changed.');

            return self::SUCCESS;
        }

        $issued = 0;
        $query->chunkById(100, function ($reservations) use ($tickets, &$issued) {
            foreach ($reservations as $reservation) {
                // Recheck status and ticket existence inside the issuer's row lock.
                $ticket = $tickets->issue($reservation);
                if ($ticket?->wasRecentlyCreated) {
                    $issued++;
                }
            }
        });
        $this->info('Issued '.$issued.' missing QR ticket(s). Existing tickets and reservation history were preserved.');

        return self::SUCCESS;
    }
}
