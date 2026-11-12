<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Services\CanonicalDocumentPreview;
use App\Services\ReservationDocumentPreview;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ReservationDocumentPreviewController extends Controller
{
    private function reservation(Request $request, string $reservation): Reservation
    {
        return ($request->user()->role === UserRole::Admin ? Reservation::query() : $request->user()->reservations())
            ->whereKey($reservation)->firstOrFail();
    }

    public function show(Request $request, string $reservation, ReservationDocumentPreview $preview): Response
    {
        $record = $this->reservation($request, $reservation);
        $role = $request->user()->role === UserRole::Admin ? 'admin' : 'resident';
        if ($record->document()->exists()) {
            return redirect()->route($role.'.reservations.generated.show', $record);
        }
        if ($request->isMethod('HEAD')) {
            return response('', 200, ['Cache-Control' => 'private, no-store']);
        }
        $artifact = null;
        $error = null;
        $status = 200;
        $headers = [];
        try {
            $artifact = app(CanonicalDocumentPreview::class)->create($record, $request->user());
        } catch (ValidationException $exception) {
            $error = collect($exception->errors())->flatten()->first();
        } catch (TooManyRequestsHttpException $exception) {
            $error = $exception->getMessage();
            $status = 429;
            $headers = $exception->getHeaders();
        }

        return response()->view('reservations.document-preview', [
            'roleLabel' => ucfirst($role), 'navigation' => config('navigation.'.$role), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => 'Preview Document', 'pageHeading' => 'Preview Document', 'pageSection' => 'Reservation Details',
            'reservation' => $record, 'artifact' => $artifact, 'previewError' => $error, 'documentRole' => $role, 'backUrl' => route($role.'.reservations.show', $record),
        ], $status, $headers)->header('Cache-Control', 'private, no-store');
    }

    public function pdf(Request $request, string $reservation, ReservationDocumentPreview $preview): Response
    {
        $record = $this->reservation($request, $reservation);

        abort_unless(is_string($request->query('preview')), 410, CanonicalDocumentPreview::STALE);
        try {
            // GET delivers the PDF for review; HEAD validates metadata without recording a review.
            $bytes = app(CanonicalDocumentPreview::class)->reviewed(
                $record, $request->user(), $request->query('preview'), markReviewed: $request->isMethod('GET')
            );
        } catch (ValidationException) {
            abort(410, CanonicalDocumentPreview::STALE);
        }

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'; sandbox"]);
    }
}
