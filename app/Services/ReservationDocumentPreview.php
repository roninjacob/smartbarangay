<?php

namespace App\Services;

use App\Models\Reservation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReservationDocumentPreview
{
    public const UNAVAILABLE = 'A historical document preview is unavailable for this request. The original PDF or saved layout snapshot may be missing.';

    public function data(Reservation $reservation, ?string $issueDate = null): ?array
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
        $dates = [];
        if (app(DocumentSystemFields::class)->usesIssueDate($reservation)) {
            $issueDate ??= $reservation->document()->first()?->generated_at?->timezone('Asia/Manila')->toDateString()
                ?? now('Asia/Manila')->toDateString();
            $dates = app(DocumentSystemFields::class)->dates($issueDate);
        }
        $placements = [];
        foreach ($reservation->documentAnswers->sortBy([['field_snapshot.sort_order', 'asc'], ['field_snapshot.id', 'asc']]) as $answer) {
            $field = $answer->field_snapshot;
            if (! is_array($field) || ! in_array($field['source'] ?? null, ['system', 'custom'], true) || ! is_string($field['name'] ?? null) || ! is_string($field['label'] ?? null) || ! is_array($field['positions'] ?? null)) {
                return null;
            }
            $value = $field['source'] === 'system' ? ($dates[$field['name']] ?? $reservation->resident_snapshot[$field['name']] ?? '') : ($answer->answer ?? '');
            if ($field['source'] === 'system' && array_key_exists($field['name'], $dates)) {
                $metadata = $field['validation_rules'] ?? [];
                $rules = match ($field['type'] ?? null) {
                    'text' => ['string', 'max:'.($metadata['max_length'] ?? 1000)],
                    'number' => ['numeric', 'between:'.($metadata['min'] ?? -1000000000).','.($metadata['max'] ?? 1000000000)],
                    'date' => ['string', 'date_format:Y-m-d'],
                    default => [],
                };
                if ($rules === [] || Validator::make(['value' => $value], ['value' => $rules])->fails()) {
                    throw ValidationException::withMessages(['document' => 'The issue date does not satisfy this request’s saved document field rules. Review the saved configuration with the administrator.']);
                }
            }
            foreach ($field['positions'] as $position) {
                if (! is_array($position)) {
                    return null;
                }
                $placements[] = [...$position, 'label' => $field['label'], 'text' => (string) $value];
            }
        }

        return $placements === [] ? null : ['placements' => $placements, 'source' => $source,
            ...($dates ? ['issue_date' => $issueDate, 'date_values' => $dates] : [])];
    }
}
