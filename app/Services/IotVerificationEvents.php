<?php

namespace App\Services;

use App\Models\IotDevice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One verification result per scanner, shared by the Admin modal and the scanner's LEDs/OLED.
 *
 * Lifecycle: opened (scan) -> pickup (an Admin viewing session is live) -> reviewing (modal open,
 * renewed by polling) -> released (acknowledged, recorded, superseded) or expired (lease lapsed
 * or absolute cap reached). Without a live Admin the scanner shows it as "standalone".
 *
 * Display state only. It never authorizes, extends evidence or performs a transaction. Callers
 * mutate it while holding the device row lock, so updates for one scanner are serialized.
 */
class IotVerificationEvents
{
    public const HOLD_REASONS = ['pickup', 'review'];

    private function key(string $device): string
    {
        return 'iot-verification:'.$device;
    }

    private function eventKey(string $event): string
    {
        return 'iot-verification-event:'.$event;
    }

    private function seconds(string $name): int
    {
        return (int) config('iot.result.'.$name);
    }

    private function nowMs(): int
    {
        return (int) now()->getTimestampMs();
    }

    private function ttl(): int
    {
        return $this->seconds('max_hold_seconds') + 120;
    }

    public function current(string $device): ?array
    {
        $entry = Cache::get($this->key($device));

        return is_array($entry) && is_string($entry['event_id'] ?? null) ? $entry : null;
    }

    private function save(string $device, array $entry): void
    {
        Cache::put($this->key($device), $entry, $this->ttl());
        Cache::put($this->eventKey($entry['event_id']), $device, $this->ttl());
    }

    private function expired(array $entry, int $now): bool
    {
        return $now >= $entry['opened_ms'] + $this->seconds('max_hold_seconds') * 1000
            || ($entry['review_until_ms'] !== null && $now > $entry['review_until_ms']);
    }

    public function underReview(?array $entry): bool
    {
        $now = $this->nowMs();

        return $entry !== null && $entry['released'] === null && $entry['review_until_ms'] !== null
            && ! $this->expired($entry, $now);
    }

    /** A scan created a new event. An active staff review is never overwritten by a later scan. */
    public function opened(string $device, string $event, bool $valid, string $outcome): bool
    {
        if ($this->underReview($this->current($device))) {
            return false;
        }
        $this->save($device, ['event_id' => $event, 'valid' => $valid, 'outcome' => $outcome,
            'opened_ms' => $this->nowMs(), 'review_until_ms' => null, 'session' => null,
            'result' => null, 'released' => null, 'released_ms' => null]);

        return true;
    }

    /** The Admin browser received this event: start its review lease. */
    public function reviewing(string $device, string $event, string $session, ?array $result): void
    {
        $entry = $this->current($device);
        if (! $entry || $entry['event_id'] !== $event || $entry['released'] !== null || $this->expired($entry, $this->nowMs())) {
            return;
        }
        $entry['session'] = $session;
        $entry['result'] = $result;
        $entry['review_until_ms'] = $this->leaseEnd($entry);
        $this->save($device, $entry);
    }

    private function leaseEnd(array $entry): int
    {
        return min($this->nowMs() + $this->seconds('review_lease_seconds') * 1000,
            $entry['opened_ms'] + $this->seconds('max_hold_seconds') * 1000);
    }

    /** Modal still open: renew the lease for this exact event and session only. */
    public function renew(string $device, string $event, string $session): void
    {
        $entry = $this->current($device);
        if ($entry && $entry['event_id'] === $event && $entry['session'] === $session && $this->underReview($entry)) {
            $entry['review_until_ms'] = $this->leaseEnd($entry);
            $this->save($device, $entry);
        }
    }

    /**
     * Release one event. Duplicate, stale or foreign acknowledgments change nothing, so an old
     * acknowledgment can never clear a newer result.
     */
    public function acknowledge(string $device, string $event, string $session, string $how = 'acknowledged'): bool
    {
        $entry = $this->current($device);
        if (! $entry || $entry['event_id'] !== $event || $entry['released'] !== null
            || ($entry['session'] !== null && $entry['session'] !== $session)) {
            return false;
        }
        $entry['released'] = $how;
        $entry['released_ms'] = $this->nowMs();
        $this->save($device, $entry);

        return true;
    }

    /** A check-in was recorded for the reviewed event. Display-only; the check-in itself is already committed. */
    public function recorded(string $event, string $session): void
    {
        $device = Cache::get($this->eventKey($event));
        if (! is_string($device)) {
            return;
        }
        DB::transaction(function () use ($device, $event, $session) {
            IotDevice::lockForUpdate()->find($device);
            $this->acknowledge($device, $event, $session, 'recorded');
        }, 3);
    }

    /** What the scanner should display for its latest result. Never includes personal data. */
    public function forDevice(string $device, bool $adminLive): ?array
    {
        $entry = $this->current($device);
        if (! $entry) {
            return null;
        }
        $now = $this->nowMs();
        $reason = match (true) {
            $entry['released'] === 'recorded' => 'recorded',
            $entry['released'] !== null => 'released',
            $this->expired($entry, $now) => 'expired',
            $entry['review_until_ms'] !== null => 'review',
            $adminLive && $now < $entry['opened_ms'] + $this->seconds('pickup_seconds') * 1000 => 'pickup',
            default => 'standalone',
        };
        $ageMs = max(0, $now - ($entry['released_ms'] ?? $entry['opened_ms']));

        return ['event_id' => $entry['event_id'], 'hold' => in_array($reason, self::HOLD_REASONS, true),
            'reason' => $reason, 'age_ms' => $ageMs];
    }

    /** The result this Admin session is still reviewing, for a refreshed or reconnected page. */
    public function activeFor(string $device, string $session): ?array
    {
        $entry = $this->current($device);
        if (! $this->underReview($entry) || $entry['session'] !== $session || ! is_array($entry['result'])) {
            return null;
        }

        return ['event_id' => $entry['event_id'], 'result' => $entry['result']];
    }
}
