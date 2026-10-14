<?php

namespace App\Services;

use App\Models\Reservation;

class ReservationDocumentPreview
{
    public const UNAVAILABLE = 'A historical document preview is unavailable for this request. The original PDF or saved layout snapshot may be missing.';

    public function data(Reservation $reservation): ?array
    {
        $reservation->loadMissing(['service.documentTemplate', 'documentAnswers']);
        $snapshot = $reservation->document_template_snapshot;
        $pdf = $reservation->service->documentTemplate;
        if (! $reservation->resident_snapshot || ! is_array($snapshot) || ! $pdf
            || ! is_string($snapshot['source_revision'] ?? null) || ! hash_equals($pdf->revision(), $snapshot['source_revision'])
            || app(CertificatePreview::class)->service($reservation->service)['kind'] !== 'pdf'
            || $reservation->documentAnswers->isEmpty()) {
            return null;
        }
        $placements = [];
        foreach ($reservation->documentAnswers->sortBy([['field_snapshot.sort_order', 'asc'], ['field_snapshot.id', 'asc']]) as $answer) {
            $field = $answer->field_snapshot;
            if (! is_array($field['positions'] ?? null)) {
                return null;
            }
            $value = $field['source'] === 'system' ? ($reservation->resident_snapshot[$field['name']] ?? '') : ($answer->answer ?? '');
            foreach ($field['positions'] as $position) {
                $placements[] = [...$position, 'label' => $field['label'], 'text' => (string) $value];
            }
        }

        return $placements === [] ? null : ['placements' => $placements];
    }
}
