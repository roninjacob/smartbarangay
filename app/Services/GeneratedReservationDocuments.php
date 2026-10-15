<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationDocument;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class GeneratedReservationDocuments
{
    public const UNAVAILABLE = 'This request has no saved personalized PDF. Review Reservation Details to generate a certificate when historical information is available. Blank copies are available separately in Services & Documents.';

    public const INVALID = 'The saved certificate is unavailable or invalid. Review this request with the administrator. Existing files will not be replaced automatically.';

    /** Lightweight UI hint; bytes() remains authoritative on every private file request. */
    public function availability(Reservation $reservation): array
    {
        $reservation->loadMissing('document');
        $document = $reservation->document;
        $disk = Storage::disk('local');
        $available = $document && $document->reservation_id === $reservation->id
            && $document->mime_type === 'application/pdf' && $document->hasSafePath()
            && is_string($document->content_hash) && preg_match('/\A[0-9a-f]{64}\z/', $document->content_hash)
            && $document->file_size > 0 && $disk->exists($document->stored_path)
            && $disk->size($document->stored_path) === $document->file_size;

        return ['available' => (bool) $available, 'can_print' => $available && $reservation->status->canPrepareDocument(),
            'message' => $available ? null : ($document ? self::INVALID : self::UNAVAILABLE)];
    }

    public function generate(Reservation $reservation, User $admin): ReservationDocument
    {
        $path = null;
        try {
            return DB::transaction(function () use ($reservation, $admin, &$path) {
                $service = Service::lockForUpdate()->findOrFail($reservation->service_id);
                $record = Reservation::lockForUpdate()->findOrFail($reservation->id);
                abort_unless($record->status->canPrepareDocument(), 403);
                if ($record->document()->exists()) {
                    throw ValidationException::withMessages(['document' => 'A certificate has already been generated for this reservation. The historical document will not be overwritten.']);
                }
                $record->setRelation('service', $service);
                $data = app(ReservationDocumentPreview::class)->data($record);
                $snapshot = $record->document_template_snapshot;
                if (! $data || ! is_int($snapshot['version'] ?? null) || $snapshot['version'] < 1) {
                    throw ValidationException::withMessages(['document' => ReservationDocumentPreview::UNAVAILABLE]);
                }
                $master = $service->documentTemplate;
                $disk = Storage::disk('local');
                $bytes = app(FinalCertificatePdf::class)->render($disk->path($master->stored_path), $data['placements']);
                $path = 'reservation-documents/'.$record->id.'/'.Str::random(40).'.pdf';
                if (! $disk->put($path, $bytes)) {
                    throw new \RuntimeException('Unable to save generated certificate.');
                }

                return $record->document()->create([
                    'service_document_template_id' => $master->id, 'template_revision' => $snapshot['source_revision'],
                    'template_version' => $snapshot['version'], 'template_filename' => $snapshot['source_filename'],
                    'original_filename' => 'Certificate-'.$record->id.'.pdf', 'stored_path' => $path,
                    'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'content_hash' => hash('sha256', $bytes),
                    'generated_by' => $admin->id, 'generated_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            if ($exception instanceof ValidationException || $exception instanceof HttpException) {
                throw $exception;
            }
            report($exception);
            throw ValidationException::withMessages(['document' => 'Certificate generation failed. The PDF may use unsupported features. No existing document was changed.']);
        }
    }

    public function bytes(Reservation $reservation): string
    {
        $reservation->unsetRelation('document');
        abort_unless($this->availability($reservation)['available'], 404);
        $document = $reservation->document;
        $disk = Storage::disk('local');
        $bytes = $disk->get($document->stored_path);
        abort_unless(strlen($bytes) === $document->file_size && hash_equals($document->content_hash, hash('sha256', $bytes)), 404);

        return $bytes;
    }
}
