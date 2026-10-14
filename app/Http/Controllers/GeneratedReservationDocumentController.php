<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Services\GeneratedReservationDocuments;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GeneratedReservationDocumentController extends Controller
{
    public function store(Request $request, Reservation $reservation, GeneratedReservationDocuments $documents): Response
    {
        $documents->generate($reservation, $request->user());

        return to_route('admin.reservations.show', $reservation)->with('status', 'Certificate generated. Printing does not change reservation status.');
    }

    private function reservation(Request $request, string $reservation): Reservation
    {
        return ($request->user()->role === UserRole::Admin ? Reservation::query() : $request->user()->reservations())
            ->whereKey($reservation)->firstOrFail();
    }

    public function show(Request $request, string $reservation, GeneratedReservationDocuments $documents): Response
    {
        $record = $this->reservation($request, $reservation);
        $documents->bytes($record);
        $role = $request->user()->role === UserRole::Admin ? 'admin' : 'resident';

        return response()->view('reservations.generated-document', [
            'roleLabel' => ucfirst($role), 'navigation' => config('navigation.'.$role), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => 'Preview Generated Document', 'pageHeading' => 'Preview Generated Document', 'pageSection' => 'Reservation Details',
            'reservation' => $record, 'backUrl' => route($role.'.reservations.show', $record),
            'preview' => ['kind' => 'pdf', 'url' => route($role.'.reservations.generated.pdf', $record)],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function pdf(Request $request, string $reservation, GeneratedReservationDocuments $documents): Response
    {
        $record = $this->reservation($request, $reservation);

        return response($documents->bytes($record), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="Certificate.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'; sandbox",
        ]);
    }

    public function download(Request $request, string $reservation, GeneratedReservationDocuments $documents): Response
    {
        $response = $this->pdf($request, $reservation, $documents);
        $response->headers->set('Content-Disposition', 'attachment; filename="Certificate.pdf"');

        return $response;
    }
}
