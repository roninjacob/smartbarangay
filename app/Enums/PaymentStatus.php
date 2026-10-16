<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';
    case NoPaymentRequired = 'no_payment_required';
    case FeeNotRecorded = 'fee_not_recorded';
    case IntegrityIssue = 'integrity_issue';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Unpaid => 'Unpaid',
            self::NoPaymentRequired => 'No Payment Required',
            self::FeeNotRecorded => 'Fee Not Recorded',
            self::IntegrityIssue => 'Payment integrity issue',
        };
    }
}
