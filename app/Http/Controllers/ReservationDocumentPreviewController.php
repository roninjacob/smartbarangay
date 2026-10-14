<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Admin\ServiceDocumentTemplateController;
use App\Models\Reservation;
use App\Models\Service;
use App\Services\ReservationDocumentPreview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

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
        $data = $preview->data($record);
        if ($data) {
            $data['pdf_url'] = route($role.'.reservations.document-preview.pdf', [$record, 'revision' => $record->document_template_snapshot['source_revision']]);
        }

        return response()->view('reservations.document-preview', [
            'roleLabel' => ucfirst($role), 'navigation' => config('navigation.'.$role), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => 'Preview Document', 'pageHeading' => 'Preview Document', 'pageSection' => 'Reservation Details',
            'reservation' => $record, 'previewData' => $data, 'backUrl' => route($role.'.reservations.show', $record),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function pdf(Request $request, string $reservation, ReservationDocumentPreview $preview): Response
    {
        $record = $this->reservation($request, $reservation);

        return DB::transaction(function () use ($request, $record, $preview) {
            $service = Service::lockForUpdate()->findOrFail($record->service_id);
            $record->setRelation('service', $service);
            abort_unless($preview->data($record) && is_string($request->query('revision'))
                && hash_equals($record->document_template_snapshot['source_revision'], $request->query('revision')), 404);
            $validated = app(ServiceDocumentTemplateController::class)->preview($service);

            // Read the validated source while the Service lock excludes replacements.
            return response(Storage::disk('local')->get($service->documentTemplate->stored_path), 200, $validated->headers->all());
        });
    }
}
