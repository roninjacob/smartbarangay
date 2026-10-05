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
}
