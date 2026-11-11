<?php

namespace App\Services;

use App\Models\Reservation;
use Carbon\CarbonImmutable;

class DocumentSystemFields
{
    public const ISSUE_POLICY = 'finalization_v1';

    public function dates(string $date): array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Manila');
        if (! $day || $day->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Invalid document issue date.');
        }

        return ['date_issued' => $date, 'document_date_full' => $day->format('F j, Y'),
            'document_day' => $day->day, 'document_day_ordinal' => $day->format('jS'),
            'document_month' => $day->format('F'), 'document_month_number' => $day->month,
            'document_month_abbreviated' => $day->format('M'), 'document_year' => $day->year];
    }

    /** Leap-day birthdays reach the next age on March 1 in a non-leap year. */
    public function age(?string $birthdate, string $reference): ?int
    {
        if ($birthdate === null) {
            return null;
        }
        try {
            $birth = CarbonImmutable::createFromFormat('!Y-m-d', $birthdate, 'Asia/Manila');
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $reference, 'Asia/Manila');
            if (! $birth || ! $day || $birth->format('Y-m-d') !== $birthdate || $day->format('Y-m-d') !== $reference || $birth->greaterThan($day)) {
                return null;
            }

            return $day->year - $birth->year - ($day->format('m-d') < $birth->format('m-d') ? 1 : 0);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function usesIssueDate(Reservation $reservation): bool
    {
        return ($reservation->resident_snapshot['_document_issue_policy'] ?? null) === self::ISSUE_POLICY;
    }

    public function samples(): array
    {
        $date = now('Asia/Manila')->toDateString();

        return [...$this->dates($date), 'resident_name' => 'Juan Santos Dela Cruz Jr.', 'resident_first_name' => 'Juan',
            'resident_middle_name' => 'Santos', 'resident_last_name' => 'Dela Cruz', 'resident_suffix' => 'Jr.',
            'resident_birthdate' => '2000-02-29', 'resident_age' => $this->age('2000-02-29', $date),
            'resident_address' => '12, Sample Street, Purok 1, Barangay Calayo, Nasugbu, Batangas',
            'resident_house_unit_number' => '12', 'resident_address_street' => 'Sample Street',
            'resident_address_zone_purok' => 'Purok 1', 'resident_contact' => '0912 345 6789',
            'resident_civil_status' => 'Single', 'resident_occupation' => 'Teacher',
            'barangay_name' => 'Barangay Calayo', 'municipality_name' => 'Nasugbu', 'province_name' => 'Batangas'];
    }
}
