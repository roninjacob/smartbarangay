<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\DocumentTemplate;
use App\Models\Service;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentTemplateConfiguration
{
    public function create(Service $service, array $data, int $admin): void
    {
        DB::transaction(function () use ($service, $data, $admin) {
            app(AccountEligibility::class)->lock($admin, UserRole::Admin);
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
                'source_revision' => $pdf->revision(), 'source_content_hash' => app(ReservationDocumentSource::class)->current($locked)['hash'], 'source_filename' => $pdf->original_filename,
                'version' => 1, 'is_active' => $data['is_active'], 'created_by' => $admin])->save();
            $audit = app(ConfigurationAudit::class);
            $audit->record(User::findOrFail($admin), $template, 'created', [], $audit->values($template));
        });
    }

    public function reconfigure(Service $service, array $data): void
    {
        $this->mutate($service, (int) $data['version'], function ($template) use ($data) {
            $source = app(ReservationDocumentSource::class)->current($template->service);
            if ($source['template_id'] !== (int) $data['pdf_id'] || ! hash_equals($source['revision'], $data['pdf_revision'])) {
                throw ValidationException::withMessages(['template' => 'The master changed. Reload before reconfiguring.']);
            }
            foreach ($template->fields()->get() as $field) {
                $field->positions()->delete();
            }
            $template->forceFill(['service_document_template_id' => $source['template_id'], 'source_revision' => $source['revision'],
                'source_content_hash' => $source['hash'], 'source_filename' => $template->service->documentTemplate->original_filename,
                'is_active' => false]);
        });
    }

    public function mutate(Service $service, int $version, Closure $change): void
    {
        DB::transaction(function () use ($service, $version, $change) {
            abort_unless(auth()->user(), 403);
            app(AccountEligibility::class)->lock(auth()->user(), UserRole::Admin);
            Service::lockForUpdate()->findOrFail($service->id);
            $template = $service->dynamicDocumentTemplate()->lockForUpdate()->firstOrFail();
            if ($template->version !== $version) {
                throw ValidationException::withMessages(['template' => 'This configuration has changed. Reload it before saving.']);
            }
            $audit = app(ConfigurationAudit::class);
            $old = $audit->values($template);
            $change($template);
            if ($template->matchesCurrentPdf()) {
                $source = app(ReservationDocumentSource::class)->current($template->service);
                $positions = $template->fields()->with('positions')->get()->flatMap(fn ($field) => $field->positions->toArray())->all();
                app(DocumentLayout::class)->validate(Storage::disk('local')->path($source['path']), $positions);
            }
            $template->version++;
            $template->save();
            abort_unless(auth()->user() instanceof User, 403);
            $audit->record(auth()->user(), $template, 'updated', $old, $audit->values($template));
        });
    }
}
