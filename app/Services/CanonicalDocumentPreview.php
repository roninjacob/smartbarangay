<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CanonicalDocumentPreview
{
    public const STALE = 'This preview has expired or changed. Open Preview Document again and review it before finalizing.';

    public const RENDERER = 'canonical-dejavu-4pt-v1';

    public function fingerprint(Reservation $reservation, array $data): string
    {
        return hash('sha256', json_encode([self::RENDERER, $reservation->id, $reservation->service_id,
            $reservation->resident_snapshot, $reservation->document_template_snapshot,
            $data['source'], $data['placements']], JSON_THROW_ON_ERROR));
    }

    public function create(Reservation $reservation, User $user): array
    {
        $path = null;
        try {
            return DB::transaction(function () use ($reservation, $user, &$path) {
                Service::lockForUpdate()->findOrFail($reservation->service_id);
                $record = Reservation::lockForUpdate()->findOrFail($reservation->id);
                $data = app(ReservationDocumentPreview::class)->data($record);
                if (! $data) {
                    throw ValidationException::withMessages(['document' => ReservationDocumentPreview::UNAVAILABLE]);
                }
                $disk = Storage::disk('local');
                $bytes = app(FinalCertificatePdf::class)->render($disk->path($data['source']['path']), $data['placements']);
                $token = Str::random(40);
                $path = 'document-previews/'.$record->id.'/'.$token.'.pdf';
                if (! $disk->put($path, $bytes)) {
                    throw new \RuntimeException('Preview storage unavailable.');
                }
                $artifact = ['token' => $token, 'user_id' => $user->id, 'reservation_id' => $record->id,
                    'path' => $path, 'fingerprint' => $this->fingerprint($record, $data),
                    'viewed' => false, 'hash' => hash('sha256', $bytes), 'size' => strlen($bytes), 'expires' => now()->addMinutes(30)->timestamp];
                if (! Cache::put('document-preview:'.$token, $artifact, now()->addMinutes(30))) {
                    throw new \RuntimeException('Preview cache unavailable.');
                }

                return $artifact;
            });
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            report($exception);
            throw ValidationException::withMessages(['document' => 'The document preview could not be prepared. Review the source and layout or try again.']);
        }
    }

    public function reviewed(Reservation $reservation, User $user, ?string $token, bool $finalizing = false): string
    {
        $artifact = is_string($token) && preg_match('/\A[A-Za-z0-9]{40}\z/', $token)
            ? Cache::get('document-preview:'.$token) : null;
        $data = app(ReservationDocumentPreview::class)->data($reservation);
        $disk = Storage::disk('local');
        if (! is_array($artifact) || ($artifact['user_id'] ?? null) !== $user->id || ($artifact['reservation_id'] ?? null) !== $reservation->id
            || ($finalizing && ! ($artifact['viewed'] ?? false)) || ($artifact['expires'] ?? 0) <= now()->timestamp || ! $data
            || ! is_string($artifact['fingerprint'] ?? null) || ! is_string($artifact['hash'] ?? null)
            || ! hash_equals($artifact['fingerprint'], $this->fingerprint($reservation, $data))
            || ($artifact['path'] ?? null) !== 'document-previews/'.$reservation->id.'/'.$token.'.pdf'
            || ! $disk->exists($artifact['path']) || $disk->size($artifact['path']) !== $artifact['size']) {
            $this->stale();
        }
        $bytes = $disk->get($artifact['path']);
        if (! hash_equals($artifact['hash'], hash('sha256', $bytes))) {
            $this->stale();
        }

        if (! $finalizing) {
            $artifact['viewed'] = true;
            Cache::put('document-preview:'.$token, $artifact, now()->addSeconds(max(1, $artifact['expires'] - now()->timestamp)));
        }

        return $bytes;
    }

    public function cleanup(): int
    {
        $disk = Storage::disk('local');
        $deleted = 0;
        // Cache tokens live for 30 minutes. A full day protects every still-valid workflow.
        foreach ($disk->allFiles('document-previews') as $path) {
            $token = pathinfo($path, PATHINFO_FILENAME);
            $artifact = Cache::get('document-preview:'.$token);
            if (is_array($artifact) && ($artifact['path'] ?? null) === $path && ($artifact['expires'] ?? 0) > now()->timestamp) {
                continue;
            }
            if (preg_match('#\Adocument-previews/[0-9]+/[A-Za-z0-9]{40}\.pdf\z#D', $path)
                && $disk->lastModified($path) < now()->subDay()->timestamp && $disk->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function stale(): never
    {
        throw ValidationException::withMessages(['document' => self::STALE]);
    }
}
