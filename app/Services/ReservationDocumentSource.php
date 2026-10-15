<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Service;
use App\Rules\OfficialDocumentTemplate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationDocumentSource
{
    public function current(Service $service): array
    {
        $service->unsetRelation('documentTemplate');
        $master = $service->documentTemplate;
        if (! $master || app(CertificatePreview::class)->service($service)['kind'] !== 'pdf') {
            $this->unavailable();
        }
        $disk = Storage::disk('local');
        $bytes = $disk->get($master->stored_path);
        if (strlen($bytes) !== $master->file_size) {
            $this->unavailable();
        }

        return ['service_id' => $service->id, 'template_id' => $master->id, 'revision' => $master->revision(), 'path' => $master->stored_path,
            'size' => strlen($bytes), 'hash' => hash('sha256', $bytes), 'mime' => 'application/pdf'];
    }

    public function preserve(Reservation $reservation, array &$paths): array
    {
        $source = $this->current($reservation->service);
        $disk = Storage::disk('local');
        $path = 'reservation-sources/'.$reservation->id.'/'.Str::random(40).'.pdf';
        $paths[] = $path;
        $bytes = $disk->get($source['path']);
        if (! hash_equals($source['hash'], hash('sha256', $bytes)) || ! $disk->put($path, $bytes)) {
            $this->unavailable();
        }
        $source['path'] = $path;

        return $source;
    }

    public function historical(Reservation $reservation): array
    {
        $snapshot = $reservation->document_template_snapshot;
        if (! is_array($snapshot) || ! is_string($snapshot['source_revision'] ?? null)) {
            $this->unavailable();
        }
        if (array_key_exists('source', $snapshot)) {
            $source = $snapshot['source'];
            $disk = Storage::disk('local');
            if (! is_array($source) || ($source['service_id'] ?? null) !== $reservation->service_id
                || ! is_int($source['template_id'] ?? null) || $source['template_id'] < 1 || ! is_string($source['path'] ?? null)
                || ! preg_match('#\Areservation-sources/'.$reservation->id.'/[A-Za-z0-9]{40}\.pdf\z#D', $source['path'])
                || ($source['mime'] ?? null) !== 'application/pdf' || ! is_int($source['size'] ?? null)
                || ! is_string($source['hash'] ?? null) || ! preg_match('/\A[0-9a-f]{64}\z/', $source['hash'])
                || ($source['revision'] ?? null) !== $snapshot['source_revision']
                || ! $disk->exists($source['path']) || $disk->size($source['path']) !== $source['size']
                || ! hash_equals($source['hash'], hash_file('sha256', $disk->path($source['path'])))
                || ! (new OfficialDocumentTemplate)->validPdf($disk->path($source['path']))) {
                $this->unavailable();
            }

            return $source;
        }
        // Compatibility only when the original reference still proves the current file is the same source.
        // A replaced/removed source is never substituted and historical records are never rewritten.
        $source = $this->current($reservation->service);
        $configuration = $reservation->service->dynamicDocumentTemplate;
        if (! hash_equals($snapshot['source_revision'], $source['revision']) || ! $configuration
            || ($snapshot['id'] ?? null) !== $configuration->id || ($snapshot['version'] ?? null) !== $configuration->version
            || ! $configuration->matchesCurrentPdf() || ! hash_equals($configuration->source_content_hash, $source['hash'])) {
            $this->unavailable();
        }

        return $source;
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['document' => ReservationDocumentPreview::UNAVAILABLE]);
    }
}
