<?php

namespace Tests\Unit;

use App\Services\DocumentSystemFields;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentSystemFieldsTest extends TestCase
{
    public static function birthdays(): array
    {
        return [['2000-11-12', '2026-11-11', 25], ['2000-11-12', '2026-11-12', 26],
            ['2000-11-12', '2026-11-13', 26], ['2000-02-29', '2026-02-28', 25],
            ['2000-02-29', '2026-03-01', 26], ['2000-02-29', '2024-02-28', 23],
            ['2000-02-29', '2024-02-29', 24], ['2000-02-29', '2024-03-01', 24],
            ['2026-11-12', '2026-11-12', 0], ['2026-11-13', '2026-11-12', null],
            [null, '2026-11-12', null], ['invalid', '2026-11-12', null], ['2001-02-29', '2026-11-12', null]];
    }

    #[DataProvider('birthdays')]
    public function test_age_uses_completed_birthdays_and_handles_missing_invalid_dates(?string $birth, string $reference, ?int $expected): void
    {
        $this->assertSame($expected, app(DocumentSystemFields::class)->age($birth, $reference));
    }

    public static function days(): array
    {
        $result = [];
        foreach (range(1, 31) as $day) {
            $suffix = match ($day) {
                1, 21, 31 => 'st', 2, 22 => 'nd', 3, 23 => 'rd', default => 'th'
            };
            $result[] = [$day, $day.$suffix];
        }

        return $result;
    }

    #[DataProvider('days')]
    public function test_plain_day_and_all_ordinal_suffix_cases_are_separate(int $day, string $ordinal): void
    {
        $date = sprintf('2026-01-%02d', $day);
        $values = app(DocumentSystemFields::class)->dates($date);
        $this->assertSame($day, $values['document_day']);
        $this->assertSame($ordinal, $values['document_day_ordinal']);
        $this->assertSame('January', $values['document_month']);
        $this->assertSame(1, $values['document_month_number']);
        $this->assertSame('Jan', $values['document_month_abbreviated']);
        $this->assertSame(2026, $values['document_year']);
        $this->assertSame($date, $values['date_issued']);
        $this->assertSame('January '.$day.', 2026', $values['document_date_full']);
    }

    public function test_designer_preview_samples_use_philippine_year_boundary(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 16:00:00', 'UTC'));
        $values = app(DocumentSystemFields::class)->samples();
        $this->assertSame('2027-01-01', $values['date_issued']);
        $this->assertSame('January 1, 2027', $values['document_date_full']);
        $this->assertSame('1st', $values['document_day_ordinal']);
        $this->assertSame(2027, $values['document_year']);
    }

    public static function calendarBoundaries(): array
    {
        return [['2026-02-28', 'February', 'Feb', 2, 28, 2026], ['2024-02-29', 'February', 'Feb', 2, 29, 2024],
            ['2026-03-01', 'March', 'Mar', 3, 1, 2026], ['2026-11-30', 'November', 'Nov', 11, 30, 2026],
            ['2026-12-31', 'December', 'Dec', 12, 31, 2026], ['2027-01-01', 'January', 'Jan', 1, 1, 2027]];
    }

    #[DataProvider('calendarBoundaries')]
    public function test_all_components_follow_the_same_calendar_date(string $date, string $month, string $abbreviation, int $number, int $day, int $year): void
    {
        $values = app(DocumentSystemFields::class)->dates($date);
        $this->assertSame($month, $values['document_month']);
        $this->assertSame($abbreviation, $values['document_month_abbreviated']);
        $this->assertSame($number, $values['document_month_number']);
        $this->assertSame($day, $values['document_day']);
        $this->assertSame($year, $values['document_year']);
    }
}
