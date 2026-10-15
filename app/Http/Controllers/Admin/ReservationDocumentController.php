<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\CertificatePrintRenderer;
use App\Services\GeneratedReservationDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReservationDocumentController extends Controller
{
    public function print(Request $request, Reservation $reservation, CertificatePrintRenderer $renderer): RedirectResponse
    {
        abort_unless($reservation->status->canPrepareDocument(), 403);
        if (! $reservation->document()->exists()) {
            return to_route('admin.reservations.show', $reservation)->withErrors(['document' => GeneratedReservationDocuments::UNAVAILABLE]);
        }
        try {
            return to_route('admin.certificates.print.show', $renderer->generatedTicket($reservation, $request->user()));
        } catch (ValidationException) {
            return to_route('admin.reservations.show', $reservation)->withErrors(['document' => GeneratedReservationDocuments::INVALID]);
        }
    }
}
