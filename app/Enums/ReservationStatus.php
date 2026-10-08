<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case ReadyForPickup = 'ready_for_pickup';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::UnderReview, self::Rejected],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved => [self::ReadyForPickup],
            self::ReadyForPickup => [self::Completed],
            self::Completed, self::Rejected, self::Cancelled => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::UnderReview => 'Under Review',
            self::Approved => 'Approved',
            self::ReadyForPickup => 'Ready for Pickup',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function residentMessage(): string
    {
        return match ($this) {
            self::Pending => 'Your request has been submitted and is waiting for review.',
            self::UnderReview => 'Barangay Calayo is reviewing your request.',
            self::Approved => 'Your request is approved. Wait for the Ready for Pickup status before collecting your document.',
            self::ReadyForPickup => 'Your document is ready for pickup. Contact the Barangay Calayo office for collection guidance.',
            self::Completed => 'Your request has been completed.',
            self::Rejected => 'Your request was rejected. Contact the Barangay Calayo office for clarification and next steps.',
            self::Cancelled => 'You cancelled this request. It will no longer be processed. You may submit a new reservation if needed.',
        };
    }

    public function canBeCancelledByResident(): bool
    {
        return $this === self::Pending;
    }

    public static function occupyingStatuses(): array
    {
        return [self::Pending, self::UnderReview, self::Approved, self::ReadyForPickup];
    }

    public function canReceiveQrTicket(): bool
    {
        return ! $this->blocksQrEligibility() && in_array($this, [self::Approved, self::ReadyForPickup], true);
    }

    public function canDisplayQrTicket(): bool
    {
        return $this->canReceiveQrTicket() || $this === self::Completed;
    }

    // A denial guard for future eligibility rules, not permission to generate a ticket for every other status.
    public function blocksQrEligibility(): bool
    {
        return in_array($this, [self::Cancelled, self::Rejected], true);
    }
}
