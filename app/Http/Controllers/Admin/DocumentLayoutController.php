<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveDocumentLayoutRequest;
use App\Models\DocumentField;
use App\Models\DocumentTemplate;
use App\Models\Service;
use App\Services\CertificatePreview;
use App\Services\DocumentTemplateConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentLayoutController extends Controller
{
    private const SYSTEM_LABELS = ['resident_name' => 'Resident Name', 'resident_address' => 'Resident Address',
        'resident_contact' => 'Resident Contact', 'date_issued' => 'Date Issued', 'barangay_name' => 'Barangay Name'];

    public function edit(Service $service, CertificatePreview $pdf): View
    {
        $template = $service->dynamicDocumentTemplate()->with('fields.positions')->firstOrFail();
        $available = $template->matchesCurrentPdf() && $pdf->service($service)['kind'] === 'pdf';
        $fields = $template->fields->sortBy('sort_order')->map(fn ($field) => [
            'key' => 'field:'.$field->id, 'field_id' => $field->id, 'system_name' => null,
            'name' => $field->field_name, 'label' => $field->label, 'source' => $field->source,
            'type' => $field->field_type->value, 'options' => $field->options,
        ])->values();
        foreach (self::SYSTEM_LABELS as $name => $label) {
            if (! $template->fields->contains('field_name', $name)) {
                $fields->push(['key' => 'system:'.$name, 'field_id' => null, 'system_name' => $name,
                    'name' => $name, 'label' => $label, 'source' => 'system', 'type' => DocumentField::SYSTEM_FIELDS[$name], 'options' => null]);
            }
        }
        $placements = $template->fields->flatMap(fn ($field) => $field->positions->map(fn ($position) => [
            'key' => 'field:'.$field->id, ...$position->only(['page_number', 'x_position', 'y_position', 'width', 'height', 'font_size', 'alignment', 'text_behavior']),
        ]))->values();

        return view('admin.document-builder.designer', [...DocumentTemplateController::shellData('Design Document Layout'),
            'service' => $service, 'template' => $template, 'available' => $available, 'designer' => [
                'fields' => $fields, 'placements' => $placements, 'version' => $template->version,
                'pdf_revision' => $template->source_revision,
                'pdf_url' => route('admin.services.document-layout.pdf', [$service, 'revision' => $template->source_revision]),
                'save_url' => route('admin.services.document-layout.update', $service),
            ]]);
    }

    public function pdf(Request $request, Service $service): StreamedResponse
    {
        return DB::transaction(function () use ($request, $service) {
            $locked = Service::lockForUpdate()->findOrFail($service->id);
            $template = $locked->dynamicDocumentTemplate()->firstOrFail();
            abort_unless(is_string($request->query('revision')) && hash_equals($template->source_revision, $request->query('revision'))
                && $template->matchesCurrentPdf(), 404);

            return app(ServiceDocumentTemplateController::class)->preview($locked);
        });
    }

    public function update(SaveDocumentLayoutRequest $request, Service $service, DocumentTemplateConfiguration $builder): JsonResponse
    {
        $data = $request->validated();
        $builder->mutate($service, (int) $data['version'], function (DocumentTemplate $template) use ($data) {
            if (! hash_equals($template->source_revision, $data['pdf_revision']) || ! $template->matchesCurrentPdf()
                || app(CertificatePreview::class)->service($template->service)['kind'] !== 'pdf') {
                throw ValidationException::withMessages(['layout' => 'The official PDF has changed or is unavailable. Reload the configuration before designing its layout.']);
            }
            // Resolve every field through this template. A failed lookup rolls back the entire layout.
            $placements = [];
            foreach ($data['placements'] as $position) {
                $id = $position['field_id'] ?? null;
                $name = $position['system_name'] ?? null;
                if ((bool) $id === (bool) $name) {
                    throw ValidationException::withMessages(['layout' => 'Choose a field from this document’s toolbox.']);
                }
                if ($id) {
                    $field = $template->fields()->find($id);
                    if (! $field) {
                        throw ValidationException::withMessages(['layout' => 'A selected field does not belong to this document. Reload the designer.']);
                    }
                } else {
                    $field = $template->fields()->firstOrCreate(['field_name' => $name], [
                        'label' => self::SYSTEM_LABELS[$name], 'source' => 'system', 'field_type' => DocumentField::SYSTEM_FIELDS[$name],
                        'is_required' => false, 'sort_order' => 0,
                    ]);
                    if ($field->source !== 'system') {
                        throw ValidationException::withMessages(['layout' => 'This system field name is unavailable.']);
                    }
                }
                unset($position['field_id'], $position['system_name']);
                $placements[] = [$field, $position];
            }
            foreach ($template->fields()->get() as $field) {
                $field->positions()->delete();
            }
            foreach ($placements as [$field, $position]) {
                $field->positions()->create($position);
            }
        });

        // Return the new configuration version for subsequent layout saves.
        return response()->json(['message' => 'Document layout saved.', 'version' => (int) $data['version'] + 1]);
    }
}
