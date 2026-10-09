<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Service;
use App\Rules\OfficialDocumentTemplate;
use Illuminate\Support\Facades\Storage;

class CertificatePreview
{
    public function service(Service $service): array
    {
        $template = $service->documentTemplate;
        $disk = Storage::disk('local');
        if (! $template || $template->mime_type !== 'application/pdf'
            || ! preg_match('#^service-document-templates/'.$service->id.'/[A-Za-z0-9]{40}\.pdf$#D', $template->stored_path)
            || ! $disk->exists($template->stored_path)
            || ! (new OfficialDocumentTemplate)->validPdf($disk->path($template->stored_path))) {
            return ['kind' => 'unavailable'];
        }

        return ['kind' => 'pdf', 'url' => route('admin.services.template.preview', $service)];
    }

    public function reservation(Reservation $reservation): array
    {
        return $this->service($reservation->service);
    }
}
