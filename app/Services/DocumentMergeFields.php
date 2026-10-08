<?php

namespace App\Services;

use App\Models\Reservation;
use Illuminate\Support\Carbon;

class DocumentMergeFields
{
    public const LABELS = [
        'resident_name' => "Resident's name", 'resident_address' => "Resident's address",
        'resident_contact' => "Resident's contact number", 'service_name' => 'Service name',
        'purpose' => 'Reservation purpose', 'reservation_reference' => 'Request reference',
        'schedule_date' => 'Schedule date', 'schedule_time' => 'Schedule time',
        'date_issued' => 'Generation date (Philippine time)', 'barangay_name' => 'Barangay', 'municipality' => 'Municipality',
    ];

    public function values(Reservation $reservation): array
    {
        return [
            'resident_name' => $reservation->user->name, 'resident_address' => $reservation->user->address,
            'resident_contact' => $reservation->user->contact_number, 'service_name' => $reservation->service->name,
            'purpose' => $reservation->purpose, 'reservation_reference' => 'Request #'.$reservation->id,
            'schedule_date' => $reservation->schedule->date->format('F j, Y'),
            'schedule_time' => Carbon::parse($reservation->schedule->start_time)->format('g:i A').' – '.Carbon::parse($reservation->schedule->end_time)->format('g:i A'),
            'date_issued' => now('Asia/Manila')->format('F j, Y'),
            'barangay_name' => 'Barangay Calayo', 'municipality' => 'Nasugbu, Batangas',
        ];
    }
}
