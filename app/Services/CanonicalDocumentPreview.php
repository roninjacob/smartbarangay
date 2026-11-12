<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class CanonicalDocumentPreview
{
    public const STALE = 'This preview has expired or changed. Open Preview Document again and review it before finalizing.';

    public const RENDERER = 'canonical-dejavu-4pt-v1';

    public const PAGE_LIMIT = 30;

    public const GENERATION_MINUTE_LIMIT = 6;

    public const GENERATION_HOUR_LIMIT = 30;

    public function fingerprint(Reservation $reservation, array $data): string
    {
        $values = [self::RENDERER, $reservation->id, $reservation->service_id,
            $reservation->resident_snapshot, $reservation->document_template_snapshot,
            $data['source'], $data['placements']];
        if (isset($data['issue_date'])) {
            $values[] = $data['issue_date'];
        }

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public function create(Reservation $reservation, User $user): array
    {
        $path = null;
        $token = null;
        $lock = null;
        $acquired = false;
        try {
            $this->budget('page:'.$user->id, self::PAGE_LIMIT, 60);
            $lock = Cache::lock('document-preview-create:'.$user->id, 120);
            $acquired = $lock->get();
            if (! $acquired) {
                throw new TooManyRequestsHttpException(2, 'Another PDF preview is being prepared for your account. Try again in 2 seconds.');
            }
            $deadline = now()->addSeconds(120)->timestamp;
            // Read/recheck under short locks; render after releasing database locks.
            [$record, $data, $previous, $reusable] = DB::transaction(function () use ($reservation, $user) {
                [$record, $data] = $this->lockedData($reservation, $user);
                $previous = Cache::get($this->currentKey($record, $user));
                $candidate = is_array($previous) && is_string($previous['token'] ?? null)
                    && preg_match('/\A[A-Za-z0-9]{40}\z/', $previous['token'])
                    ? Cache::get('document-preview:'.$previous['token']) : null;
                try {
                    $this->artifactBytes($record, $user, $previous['token'] ?? null, $candidate, $data);
                    $reusable = $candidate;
                } catch (ValidationException) {
                    $reusable = null;
                }
                if (! $reusable) {
                    $this->budget('generation-minute:'.$user->id, self::GENERATION_MINUTE_LIMIT, 60);
                    $this->budget('generation-hour:'.$user->id, self::GENERATION_HOUR_LIMIT, 3600);
                }

                return [$record, $data, $previous, $reusable];
            });
            if ($reusable) {
                // Review state lives in the protected token entry, never the reuse index.
                return $reusable;
            }
            $fingerprint = $this->fingerprint($record, $data);
            $disk = Storage::disk('local');
            $bytes = app(FinalCertificatePdf::class)->render($disk->path($data['source']['path']), $data['placements']);
            if (now()->timestamp >= $deadline || ! $lock->isOwnedByCurrentProcess()) {
                throw new TooManyRequestsHttpException(2, 'PDF preparation took too long. Open the preview again.');
            }

            $artifact = DB::transaction(function () use ($reservation, $user, $fingerprint, $bytes, $lock, $deadline, &$path, &$token) {
                [$record, $data] = $this->lockedData($reservation, $user);
                if (now()->timestamp >= $deadline || ! $lock->isOwnedByCurrentProcess()) {
                    throw new TooManyRequestsHttpException(2, 'PDF preparation took too long. Open the preview again.');
                }
                if (! hash_equals($fingerprint, $this->fingerprint($record, $data))) {
                    $this->stale();
                }
                $token = Str::random(40);
                $path = 'document-previews/'.$record->id.'/'.$token.'.pdf';
                if (! Storage::disk('local')->put($path, $bytes)) {
                    throw new \RuntimeException('Preview storage unavailable.');
                }
                $artifact = ['token' => $token, 'user_id' => $user->id, 'reservation_id' => $record->id,
                    'path' => $path, 'fingerprint' => $this->fingerprint($record, $data),
                    'issue_date' => $data['issue_date'] ?? null, 'date_values' => $data['date_values'] ?? [],
                    'viewed' => false, 'hash' => hash('sha256', $bytes), 'size' => strlen($bytes), 'expires' => now()->addMinutes(30)->timestamp];
                if (! Cache::put('document-preview:'.$token, $artifact, now()->addMinutes(30))) {
                    throw new \RuntimeException('Preview cache unavailable.');
                }
                // Retain the pointer longer than the token so an expired replacement
                // can retire the preceding temporary file for this owner/reservation.
                if (! Cache::put($this->currentKey($record, $user), $artifact, now()->addDay())) {
                    throw new \RuntimeException('Preview cache unavailable.');
                }

                return $artifact;
            });
            $this->retire($previous, $record, $user);

            return $artifact;
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            if ($token) {
                Cache::forget('document-preview:'.$token);
            }
            if ($exception instanceof ValidationException || $exception instanceof HttpException) {
                throw $exception;
            }
            report($exception);
            throw ValidationException::withMessages(['document' => 'The document preview could not be prepared. Review the source and layout or try again.']);
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    private function lockedData(Reservation $reservation, User $user): array
    {
        $actor = app(AccountEligibility::class)->lock($user, $user->role);
        Service::lockForUpdate()->findOrFail($reservation->service_id);
        $record = Reservation::lockForUpdate()->findOrFail($reservation->id);
        abort_unless($actor->role === UserRole::Admin || $record->user_id === $actor->id, 404);
        if ($record->document()->exists()) {
            throw ValidationException::withMessages(['document' => 'This certificate has already been finalized. Open the saved generated document.']);
        }
        $data = app(ReservationDocumentPreview::class)->data($record);
        if (! $data) {
            throw ValidationException::withMessages(['document' => ReservationDocumentPreview::UNAVAILABLE]);
        }

        return [$record, $data];
    }

    private function budget(string $context, int $limit, int $seconds): void
    {
        $key = 'document-preview-budget:'.$context;
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retry = max(1, RateLimiter::availableIn($key));
            throw new TooManyRequestsHttpException($retry, 'Too many PDF preview requests. Try again in '.$retry.' seconds.');
        }
        RateLimiter::hit($key, $seconds);
    }

    private function currentKey(Reservation $reservation, User $user): string
    {
        return 'document-preview-current:'.$user->id.':'.$reservation->id;
    }

    private function retire(mixed $artifact, Reservation $reservation, User $user): void
    {
        $token = is_array($artifact) ? ($artifact['token'] ?? null) : null;
        if (is_string($token) && preg_match('/\A[A-Za-z0-9]{40}\z/', $token)
            && ($artifact['user_id'] ?? null) === $user->id && ($artifact['reservation_id'] ?? null) === $reservation->id
            && ($artifact['path'] ?? null) === 'document-previews/'.$reservation->id.'/'.$token.'.pdf') {
            Cache::forget('document-preview:'.$token);
            Storage::disk('local')->delete($artifact['path']);
        }
    }

    public function reviewed(Reservation $reservation, User $user, ?string $token, bool $finalizing = false, bool $markReviewed = true, ?string $finalizationDate = null): string
    {
        $artifact = is_string($token) && preg_match('/\A[A-Za-z0-9]{40}\z/', $token)
            ? Cache::get('document-preview:'.$token) : null;
        if (app(DocumentSystemFields::class)->usesIssueDate($reservation)) {
            if (! is_array($artifact) || ! is_string($artifact['issue_date'] ?? null)
                || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $artifact['issue_date'])
                || ($finalizing && $artifact['issue_date'] !== ($finalizationDate ?? now('Asia/Manila')->toDateString()))) {
                $this->stale();
            }
        }
        try {
            $data = app(ReservationDocumentPreview::class)->data($reservation, $artifact['issue_date'] ?? null);
        } catch (\InvalidArgumentException) {
            $this->stale();
        }
        $bytes = $this->artifactBytes($reservation, $user, $token, $artifact, $data, $finalizing);

        if (! $finalizing && $markReviewed) {
            $artifact['viewed'] = true;
            Cache::put('document-preview:'.$token, $artifact, now()->addSeconds(max(1, $artifact['expires'] - now()->timestamp)));
        }

        return $bytes;
    }

    private function artifactBytes(Reservation $reservation, User $user, ?string $token, mixed $artifact, ?array $data, bool $finalizing = false): string
    {
        $disk = Storage::disk('local');
        if (! is_string($token) || ! preg_match('/\A[A-Za-z0-9]{40}\z/', $token)
            || ! is_array($artifact) || ($artifact['token'] ?? null) !== $token
            || ($artifact['user_id'] ?? null) !== $user->id || ($artifact['reservation_id'] ?? null) !== $reservation->id
            || ($finalizing && ! ($artifact['viewed'] ?? false)) || ! is_int($artifact['expires'] ?? null)
            || $artifact['expires'] <= now()->timestamp || ! is_int($artifact['size'] ?? null) || $artifact['size'] < 1 || ! $data
            || ($artifact['issue_date'] ?? null) !== ($data['issue_date'] ?? null)
            || ($artifact['date_values'] ?? []) !== ($data['date_values'] ?? [])
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
