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
}
