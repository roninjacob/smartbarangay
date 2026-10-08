<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Rules\OfficialDocumentTemplate;
use App\Services\DocxTemplateRenderer;
use App\Services\ReservationDocumentPreparer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationDocumentController extends Controller
{
    public function store(Request $request, Reservation $reservation, ReservationDocumentPreparer $documents): RedirectResponse
    {
        $documents->prepare($reservation, $request->user());

        return to_route('admin.reservations.show', $reservation)->with('status', 'Ready-to-print document prepared. Review, print and sign it before explicitly updating the request status.');
    }

    public function download(Reservation $reservation, DocxTemplateRenderer $renderer): StreamedResponse
    {
        $document = $reservation->document()->firstOrFail();
        $disk = Storage::disk('local');
        abort_unless($document->hasSafePath() && $disk->exists($document->stored_path), 404);
        abort_unless($renderer->validFile($disk->path($document->stored_path)), 404);

        return $disk->download($document->stored_path, $document->original_filename, [
            'Content-Type' => OfficialDocumentTemplate::DOCX_MIME, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
