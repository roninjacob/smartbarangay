<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveDocumentTemplateRequest;
use App\Models\Service;
use App\Services\CertificatePreview;
use App\Services\DocumentTemplateConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DocumentTemplateController extends Controller
{
    public function index(Service $service, CertificatePreview $pdf): View
    {
        $template = $service->dynamicDocumentTemplate()->with(['creator', 'fields.positions'])->first();

        return view('admin.document-builder.index', [...self::shellData('Document Template Configuration'),
            'service' => $service, 'template' => $template, 'pdfAvailable' => $pdf->service($service)['kind'] === 'pdf']);
    }

    public function store(SaveDocumentTemplateRequest $request, Service $service, DocumentTemplateConfiguration $builder): RedirectResponse
    {
        $builder->create($service, $request->validated(), $request->user()->id);

        return to_route('admin.services.document-configuration.index', $service)->with('status', 'Document configuration created. The official PDF is unchanged.');
    }

    public function update(SaveDocumentTemplateRequest $request, Service $service, DocumentTemplateConfiguration $builder): RedirectResponse
    {
        $builder->mutate($service, $request->integer('version'), fn ($template) => $template->fill($request->safe()->only('is_active')));

        return to_route('admin.services.document-configuration.index', $service)->with('status', 'Configuration status updated.');
    }

    public static function shellData(string $heading): array
    {
        return ['roleLabel' => 'Admin', 'navigation' => config('navigation.admin'), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => $heading, 'pageHeading' => $heading, 'pageSection' => 'Services & Documents'];
    }
}
