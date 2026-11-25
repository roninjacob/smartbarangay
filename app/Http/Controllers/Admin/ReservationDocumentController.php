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
        // Ready for Pickup with a recorded check-in; the document check below keeps its existing message.
        abort_if(app(GeneratedReservationDocuments::class)->finalizeBlock($reservation) !== null, 403);
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
