<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use App\Models\Service;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentTemplateConfiguration
{
    public function create(Service $service, array $data, int $admin): void
    {
        DB::transaction(function () use ($service, $data, $admin) {
            $locked = Service::lockForUpdate()->findOrFail($service->id);
            if ($locked->dynamicDocumentTemplate()->exists()) {
                throw ValidationException::withMessages(['template' => 'A configuration already exists for this service.']);
            }
            $pdf = $locked->documentTemplate()->lockForUpdate()->first();
            if (! $pdf || $pdf->id !== (int) $data['pdf_id'] || ! hash_equals($pdf->revision(), $data['pdf_revision'])
                || app(CertificatePreview::class)->service($locked)['kind'] !== 'pdf') {
                throw ValidationException::withMessages(['template' => 'The official PDF is unavailable or has changed. Review the current file before creating a configuration.']);
            }
            $template = new DocumentTemplate;
            $template->forceFill(['service_id' => $locked->id, 'service_document_template_id' => $pdf->id,
                'source_revision' => $pdf->revision(), 'source_filename' => $pdf->original_filename,
                'version' => 1, 'is_active' => $data['is_active'], 'created_by' => $admin])->save();
        });
    }

    public function mutate(Service $service, int $version, Closure $change): void
    {
        DB::transaction(function () use ($service, $version, $change) {
            Service::lockForUpdate()->findOrFail($service->id);
            $template = $service->dynamicDocumentTemplate()->lockForUpdate()->firstOrFail();
            if ($template->version !== $version) {
                throw ValidationException::withMessages(['template' => 'This configuration has changed. Reload it before saving.']);
            }
            $change($template);
            $template->version++;
            $template->save();
        });
    }
}
