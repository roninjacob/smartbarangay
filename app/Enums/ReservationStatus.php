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

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::UnderReview, self::Rejected],
            self::UnderReview => [self::Approved, self::Rejected],
            self::Approved => [self::ReadyForPickup],
            self::ReadyForPickup => [self::Completed],
            self::Completed, self::Rejected => [],
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
        };
    }
}
