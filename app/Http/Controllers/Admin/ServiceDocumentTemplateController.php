<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Rules\OfficialDocumentTemplate;
use App\Services\CertificatePrintRenderer;
use App\Services\ServiceDocumentTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServiceDocumentTemplateController extends Controller
{
    public function print(Request $request, Service $service, CertificatePrintRenderer $renderer): RedirectResponse
    {
        try {
            $pdf = $renderer->service($service);

            return to_route('admin.certificates.print.show', $renderer->ticket($pdf, $request->user(), $service));
        } catch (ValidationException $exception) {
            return to_route('admin.services.certificates.index', $service)->withErrors(['document' => CertificatePrintRenderer::ERROR]);
        }
    }

    public function preview(Service $service): StreamedResponse
    {
        $template = $service->documentTemplate()->firstOrFail();
        $disk = Storage::disk('local');
        abort_unless($template->mime_type === 'application/pdf' && preg_match('#^service-document-templates/'.$service->id.'/[A-Za-z0-9]{40}\.pdf$#D', $template->stored_path) && $disk->exists($template->stored_path), 404);
        abort_if(Validator::make(['file' => new UploadedFile($disk->path($template->stored_path), 'template.pdf', null, null, true)], ['file' => ['file', 'max:10240', new OfficialDocumentTemplate]])->fails(), 404);

        return $disk->response($template->stored_path, 'Certificate-template.pdf', [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'; sandbox",
        ], 'inline');
    }

    public function download(Service $service): StreamedResponse
    {
        $template = $service->documentTemplate()->firstOrFail();
        $this->preview($service); // Apply the same path, MIME and structure checks.

        return Storage::disk('local')->download($template->stored_path, $template->original_filename, [
            'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function destroy(Request $request, Service $service, ServiceDocumentTemplates $templates): RedirectResponse
    {
        // Explicit confirmation is enforced server-side as well as in the form.
        $request->validate(['confirm_removal' => ['required', 'accepted'], 'template_id' => ['required', 'integer'], 'template_revision' => ['required', 'string', 'size:64']]);
        $path = DB::transaction(function () use ($request, $service): string {
            $locked = Service::lockForUpdate()->findOrFail($service->id);
            $template = $locked->documentTemplate()->lockForUpdate()->firstOrFail();
            if ($template->id !== $request->integer('template_id') || ! hash_equals($template->revision(), $request->input('template_revision'))) {
                throw ValidationException::withMessages(['template_id' => 'The template has changed. Review the current template before confirming removal.']);
            }
            $path = $template->stored_path;
            $template->delete();

            return $path;
        });
        $templates->cleanup([$path]);

        return to_route('admin.services.edit', $service)->with('status', 'Official template removed. The service and existing reservation records are preserved.');
    }
}
