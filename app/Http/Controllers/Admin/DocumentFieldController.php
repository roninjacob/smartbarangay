<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DocumentFieldType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveDocumentFieldRequest;
use App\Models\DocumentField;
use App\Models\Service;
use App\Services\DocumentTemplateConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DocumentFieldController extends Controller
{
    public function create(Service $service): View
    {
        return $this->form($service, new DocumentField(['source' => 'custom', 'field_type' => DocumentFieldType::Text, 'sort_order' => 0]));
    }

    public function edit(Service $service, string $documentField): View
    {
        $field = $service->dynamicDocumentTemplate()->firstOrFail()->fields()->with('positions')->findOrFail($documentField);

        return $this->form($service, $field);
    }

    private function form(Service $service, DocumentField $field): View
    {
        return view('admin.document-builder.field', [...DocumentTemplateController::shellData($field->exists ? 'Edit Document Field' : 'Add Document Field'),
            'service' => $service, 'template' => $service->dynamicDocumentTemplate()->firstOrFail(),
            'field' => $field, 'position' => $field->exists ? $field->positions->first() : null,
            'types' => DocumentFieldType::cases()]);
    }

    public function store(SaveDocumentFieldRequest $request, Service $service, DocumentTemplateConfiguration $builder): RedirectResponse
    {
        return $this->save($request, $service, $builder);
    }

    public function update(SaveDocumentFieldRequest $request, Service $service, string $documentField, DocumentTemplateConfiguration $builder): RedirectResponse
    {
        return $this->save($request, $service, $builder, $documentField);
    }

    private function save(SaveDocumentFieldRequest $request, Service $service, DocumentTemplateConfiguration $builder, ?string $id = null): RedirectResponse
    {
        $builder->mutate($service, $request->integer('version'), function ($template) use ($request, $id) {
            $field = $id ? $template->fields()->findOrFail($id) : $template->fields()->make();
            $field->fill($request->fieldData())->save();
            $position = $field->positions()->first();
            if ($request->boolean('has_position')) {
                $position ??= $field->positions()->make();
                $position->fill($request->validated('position'))->save();
            } elseif ($position) {
                $position->delete();
            }
        });

        return to_route('admin.services.document-configuration.index', $service)->with('status', 'Document field configuration saved.');
    }

    public function destroy(Request $request, Service $service, string $documentField, DocumentTemplateConfiguration $builder): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1', 'max:2147483647']]);
        $builder->mutate($service, $data['version'], fn ($template) => $template->fields()->findOrFail($documentField)->delete());

        return to_route('admin.services.document-configuration.index', $service)->with('status', 'Field and its placement metadata deleted. The official PDF is unchanged.');
    }
}
