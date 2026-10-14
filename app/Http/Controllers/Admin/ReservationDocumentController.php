<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\CertificatePrintRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReservationDocumentController extends Controller
{
    public function print(Request $request, Reservation $reservation, CertificatePrintRenderer $renderer): RedirectResponse
    {
        abort_unless($reservation->status->canPrepareDocument(), 403);
        try {
            if ($reservation->document?->mime_type === 'application/pdf') {
                return to_route('admin.certificates.print.show', $renderer->generatedTicket($reservation, $request->user()));
            }
            $pdf = $renderer->service($reservation->service);

            return to_route('admin.certificates.print.show', $renderer->ticket($pdf, $request->user(), $reservation->service, $reservation->id));
        } catch (ValidationException) {
            return to_route('admin.reservations.show', $reservation)->withErrors(['document' => CertificatePrintRenderer::ERROR]);
        }
    }
}
