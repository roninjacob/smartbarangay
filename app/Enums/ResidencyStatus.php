<?php

namespace App\Enums;

enum ResidencyStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case NeedsCorrection = 'needs_correction';
    case Rejected = 'rejected';

    public const BLOCKED_MESSAGE = 'Barangay Calayo residency approval is required before you can submit a new request. Review your residency status in Profile / Account Settings.';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending residency review',
            self::Approved => 'Residency approved',
            self::NeedsCorrection => 'Profile corrections requested',
            self::Rejected => 'Residency rejected',
        };
    }
}
