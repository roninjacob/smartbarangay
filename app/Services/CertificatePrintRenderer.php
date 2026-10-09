<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\User;
use App\Rules\OfficialDocumentTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CertificatePrintRenderer
{
    public const ERROR = 'The official certificate PDF is unavailable. Review or upload it in Edit Service.';

    private ?array $sourceSnapshot = null;

    public function service(Service $service): string
    {
        $template = $service->documentTemplate()->first();
        if (! $template || $template->mime_type !== 'application/pdf' || ! preg_match('#^service-document-templates/'.$service->id.'/[A-Za-z0-9]{40}\.pdf$#D', $template->stored_path)) {
            $this->fail();
        }

        return $this->render($template->stored_path);
    }

    private function render(string $path): string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            $this->fail();
        }
        $source = new UploadedFile($disk->path($path), 'certificate.pdf', null, null, true);
        if (Validator::make(['file' => $source], ['file' => ['file', 'max:10240', new OfficialDocumentTemplate]])->fails()) {
            $this->fail();
        }
        $bytes = $disk->get($path);
        $this->sourceSnapshot = ['path' => $path, 'hash' => hash('sha256', $bytes)];

        return $bytes;
    }

    public function ticket(string $pdf, User $admin, Service $service, ?int $reservationId = null): string
    {
        $token = Str::random(40);
        $source = $service->documentTemplate()->firstOrFail();
        if ($this->sourceSnapshot === null || $source->stored_path !== $this->sourceSnapshot['path']
            || ! Storage::disk('local')->exists($source->stored_path)
            || ! hash_equals($this->sourceSnapshot['hash'], hash_file('sha256', Storage::disk('local')->path($source->stored_path)))) {
            $this->fail();
        }
        try {
            $saved = Cache::put('certificate-print:'.$token, ['owner' => $admin->id, 'pdf' => base64_encode($pdf), 'service' => $service->name, 'service_id' => $service->id, 'reservation_id' => $reservationId,
                'source_path' => $source->stored_path,
                'source_hash' => $this->sourceSnapshot['hash']], now()->addMinutes(10));
        } catch (Throwable $exception) {
            report($exception);
            $this->fail();
        }
        if (! $saved) {
            $this->fail();
        }

        return $token;
    }

    public function artifact(string $token, User $admin): array
    {
        $artifact = Cache::get('certificate-print:'.$token);
        abort_unless(is_array($artifact) && $artifact['owner'] === $admin->id, 404);
        $source = Service::find($artifact['service_id'])?->documentTemplate;
        if ($artifact['reservation_id']) {
            $reservation = Reservation::find($artifact['reservation_id']);
            abort_unless($reservation && $reservation->service_id === $artifact['service_id'] && $reservation->status === ReservationStatus::Approved, 404);
        }
        $disk = Storage::disk('local');
        abort_unless($source && $source->mime_type === 'application/pdf'
            && preg_match('#^service-document-templates/'.$artifact['service_id'].'/[A-Za-z0-9]{40}\.pdf$#D', $source->stored_path)
            && $source->stored_path === $artifact['source_path'] && $disk->exists($source->stored_path)
            && hash_equals($artifact['source_hash'], hash_file('sha256', $disk->path($source->stored_path))), 404);
        $artifact['pdf'] = base64_decode($artifact['pdf'], true);

        return $artifact;
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['document' => self::ERROR]);
    }
}
