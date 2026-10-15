<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveDocumentTemplateRequest;
use App\Models\Service;
use App\Services\CertificatePreview;
use App\Services\DocumentTemplateConfiguration;
use App\Services\ReservationDocumentInformation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DocumentTemplateController extends Controller
{
    public function index(Service $service, CertificatePreview $pdf): View
    {
        $template = $service->dynamicDocumentTemplate()->with(['creator', 'fields.positions'])->first();

        $readiness = 'Configuration inactive. Review fields and layout before activating.';
        if ($template?->is_active) {
            try {
                app(ReservationDocumentInformation::class)->configuration($service);
                $readiness = 'Ready for new document requests.';
            } catch (ValidationException) {
                $readiness = 'Document setup unavailable. Review the current master, fields and layout before accepting new requests.';
            }
        }

        return view('admin.document-builder.index', [...self::shellData('Document Template Configuration'),
            'service' => $service, 'template' => $template, 'readiness' => $readiness, 'pdfAvailable' => $pdf->service($service)['kind'] === 'pdf']);
    }

    public function store(SaveDocumentTemplateRequest $request, Service $service, DocumentTemplateConfiguration $builder): RedirectResponse
    {
        $builder->create($service, $request->validated(), $request->user()->id);

        return to_route('admin.services.document-configuration.index', $service)->with('status', 'Document configuration created. The official PDF is unchanged.');
    }

    public function update(SaveDocumentTemplateRequest $request, Service $service, DocumentTemplateConfiguration $builder): RedirectResponse
    {
        if ($request->boolean('reconfigure')) {
            $builder->reconfigure($service, $request->validated());

            return to_route('admin.services.document-configuration.index', $service)->with('status', 'Current master selected. Fields were retained and old placements cleared. Review the layout before activating.');
        }
        $builder->mutate($service, $request->integer('version'), function ($template) use ($request) {
            if ($request->boolean('is_active') && ! $template->matchesCurrentPdf()) {
                throw ValidationException::withMessages(['template' => 'Reconfigure against the current master before activating.']);
            }
            $template->fill($request->safe()->only('is_active'));
        });

        return to_route('admin.services.document-configuration.index', $service)->with('status', 'Configuration status updated.');
    }

    public static function shellData(string $heading): array
    {
        return ['roleLabel' => 'Admin', 'navigation' => config('navigation.admin'), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => $heading, 'pageHeading' => $heading, 'pageSection' => 'Services & Documents'];
    }
}
