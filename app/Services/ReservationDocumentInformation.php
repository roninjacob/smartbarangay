<?php

namespace App\Services;

use App\Enums\DocumentFieldType;
use App\Models\DocumentField;
use App\Models\DocumentTemplate;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReservationDocumentInformation
{
    public function configuration(Service $service): ?DocumentTemplate
    {
        return $service->dynamicDocumentTemplate()->where('is_active', true)
            ->with(['fields' => fn ($fields) => $fields->orderBy('sort_order')->orderBy('id')])->first();
    }

    public function signature(?DocumentTemplate $template): string
    {
        if (! $template) {
            return 'none';
        }

        return hash('sha256', json_encode([$template->id, $template->version, $template->source_revision,
            $template->fields->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values()->map(fn ($field) => $this->fieldSnapshot($field))->all()]));
    }

    public function profile(User $resident): array
    {
        return ['resident_name' => $resident->name, 'resident_address' => $resident->address,
            'resident_contact' => $resident->contact_number, 'date_issued' => now('Asia/Manila')->toDateString(),
            'barangay_name' => 'Barangay Calayo'];
    }

    public function validate(?DocumentTemplate $template, mixed $input, array $profile): array
    {
        $fields = $template?->fields ?? collect();
        $custom = $fields->where('source', 'custom');
        $answers = $input ?? [];
        if (! is_array($answers)) {
            throw ValidationException::withMessages(['document_answers' => 'Enter the document information using the fields shown.']);
        }
        foreach (array_keys($answers) as $id) {
            if (! $custom->contains(fn ($field) => (string) $field->id === (string) $id)) {
                throw ValidationException::withMessages(['document_answers' => 'Submit answers only for this service’s custom document fields. System information comes from your profile.']);
            }
        }
        $rules = [];
        $labels = [];
        foreach ($fields as $field) {
            $key = 'document_answers.'.$field->id;
            $labels[$key] = $field->label;
            $rules[$key] = ['bail', $field->is_required ? 'required' : 'nullable'];
            $metadata = $field->validation_rules ?? [];
            $rules[$key] = [...$rules[$key], ...match ($field->field_type) {
                DocumentFieldType::Text => ['string', 'max:'.($metadata['max_length'] ?? 1000)],
                DocumentFieldType::Number => ['numeric', 'between:'.($metadata['min'] ?? -1000000000).','.($metadata['max'] ?? 1000000000)],
                DocumentFieldType::Date => ['string', 'date_format:Y-m-d'],
                DocumentFieldType::Dropdown => ['string', 'max:100', Rule::in($field->options ?? [])],
            }];
            if ($field->source === 'system') {
                $answers[$field->id] = $profile[$field->field_name] ?? null;
            } elseif (is_string($answers[$field->id] ?? null)) {
                $answers[$field->id] = trim($answers[$field->id]);
                if ($answers[$field->id] === '') {
                    $answers[$field->id] = null;
                }
            }
        }
        $validated = Validator::make(['document_answers' => $answers], $rules, [], $labels)->validate();

        // Store only known custom inputs in the draft; system values are always recomputed server-side.
        return $custom->mapWithKeys(fn ($field) => [$field->id => $validated['document_answers'][$field->id] ?? null])->all();
    }

    public function store(Reservation $reservation, ?DocumentTemplate $template, array $answers, array $profile): void
    {
        $reservation->forceFill(['resident_snapshot' => $profile, 'document_template_snapshot' => $template ? [
            'id' => $template->id, 'version' => $template->version, 'source_revision' => $template->source_revision,
            'source_filename' => $template->source_filename,
        ] : null])->save();
        foreach ($template?->fields ?? [] as $field) {
            $value = $field->source === 'system' ? ($profile[$field->field_name] ?? null) : ($answers[$field->id] ?? null);
            $reservation->documentAnswers()->create(['document_field_id' => $field->id,
                'field_snapshot' => [...$this->fieldSnapshot($field), 'positions' => $field->positions()->get()->map(fn ($position) => $position->only(['page_number', 'x_position', 'y_position', 'width', 'height', 'font_size', 'alignment', 'text_behavior']))->all()], 'answer' => $value === null ? null : (string) $value]);
        }
    }

    private function fieldSnapshot(DocumentField $field): array
    {
        return ['id' => $field->id, 'name' => $field->field_name, 'label' => $field->label,
            'type' => $field->field_type->value, 'source' => $field->source, 'required' => $field->is_required,
            'validation_rules' => $field->validation_rules, 'options' => $field->options, 'sort_order' => $field->sort_order];
    }
}
