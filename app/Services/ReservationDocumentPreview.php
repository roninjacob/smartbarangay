<?php

namespace App\Services;

use App\Models\Reservation;

class ReservationDocumentPreview
{
    public const UNAVAILABLE = 'A historical document preview is unavailable for this request. The original PDF or saved layout snapshot may be missing.';

    public function data(Reservation $reservation): ?array
    {
        $reservation->loadMissing(['service', 'documentAnswers']);
        $snapshot = $reservation->document_template_snapshot;
        if (! is_array($reservation->resident_snapshot) || ! $reservation->resident_snapshot || ! is_array($reservation->document_template_snapshot)
            || ! is_int($snapshot['id'] ?? null) || ($snapshot['id'] ?? 0) < 1
            || ! is_int($snapshot['version'] ?? null) || ($snapshot['version'] ?? 0) < 1
            || ! is_string($snapshot['source_filename'] ?? null) || $snapshot['source_filename'] === ''
            || $reservation->documentAnswers->isEmpty()) {
            return null;
        }
        try {
            $source = app(ReservationDocumentSource::class)->historical($reservation);
        } catch (\Throwable) {
            return null;
        }
        $placements = [];
        foreach ($reservation->documentAnswers->sortBy([['field_snapshot.sort_order', 'asc'], ['field_snapshot.id', 'asc']]) as $answer) {
            $field = $answer->field_snapshot;
            if (! is_array($field) || ! in_array($field['source'] ?? null, ['system', 'custom'], true) || ! is_string($field['name'] ?? null) || ! is_string($field['label'] ?? null) || ! is_array($field['positions'] ?? null)) {
                return null;
            }
            $value = $field['source'] === 'system' ? ($reservation->resident_snapshot[$field['name']] ?? '') : ($answer->answer ?? '');
            foreach ($field['positions'] as $position) {
                if (! is_array($position)) {
                    return null;
                }
                $placements[] = [...$position, 'label' => $field['label'], 'text' => (string) $value];
            }
        }

        return $placements === [] ? null : ['placements' => $placements, 'source' => $source];
    }
}
