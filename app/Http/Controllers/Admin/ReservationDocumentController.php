<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
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
        abort_unless($reservation->status === ReservationStatus::Approved, 403);
        try {
            $pdf = $renderer->service($reservation->service);

            return to_route('admin.certificates.print.show', $renderer->ticket($pdf, $request->user(), $reservation->service, $reservation->id));
        } catch (ValidationException) {
            return to_route('admin.reservations.show', $reservation)->withErrors(['document' => CertificatePrintRenderer::ERROR]);
        }
    }
}
