<?php

namespace Tests\Support;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\CheckinLog;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CheckInReservations;
use App\Services\QrTicketIssuer;
use App\Services\QrTicketVerifier;
use App\Services\ReportCsvExports;
use App\Services\ReportingExclusions;
use App\Services\ReservationReports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait ReportIntegrityFixture
{
    private string $manifest;

    private User $reportAdmin;

    private User $reportResident;

    private Service $reportService;

    private Schedule $reportSchedule;

    private array $filters;

    private array $period;

    private string $eventTime;

    private function initializeReports(?CarbonImmutable $day = null): void
    {
        // Retained disposable databases get a new interval, without deleting old cases.
        $latest = max(array_filter([DB::table('reservations')->max('created_at'),
            DB::table('reservation_payments')->max('paid_at'), DB::table('checkin_logs')->max('verified_at'),
            DB::table('reservation_documents')->max('generated_at'), '2030-12-29 00:00:00']));
        $day ??= CarbonImmutable::parse($latest, 'UTC')->addDays(3)->timezone('Asia/Manila')->startOfDay();
        $this->travelTo($day->setTime(10, 0));
        $this->filters = ['period' => 'date', 'date' => $day->toDateString()];
        $this->period = app(ReservationReports::class)->period($this->filters);
        $this->eventTime = CarbonImmutable::parse($this->period['from'], 'UTC')->addHour()->format('Y-m-d H:i:s');
        $this->reportAdmin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->reportResident = User::factory()->residencyApproved()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $this->reportService = Service::create(['name' => 'TEST legitimate '.Str::uuid(), 'fee_amount' => '999.99']);
        $last = Schedule::max('date');
        $date = max($day->addYear()->toDateString(), $last ? CarbonImmutable::parse($last)->addDay()->toDateString() : '2000-01-01');
        $this->reportSchedule = Schedule::create(['date' => $date, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 100]);
        $this->manifest = tempnam(sys_get_temp_dir(), 'sb-phase7-manifest-');
        config(['reporting.manifest' => $this->manifest, 'reporting.exports.batch_size' => 1]);
        $this->reviewManifest();
        Storage::fake('local');
        $this->withoutVite();
    }

    private function reviewManifest(array $reservations = []): void
    {
        $entries = array_map(fn ($record) => ['reservation_id' => $record->id, 'user_id' => $record->user_id,
            'service_id' => $record->service_id, 'schedule_id' => $record->schedule_id, 'created_at' => $record->getRawOriginal('created_at')], $reservations);
        file_put_contents($this->manifest, json_encode(['version' => 1, 'reviewed' => true,
            'binding' => app(ReportingExclusions::class)->binding(), 'excluded_reservations' => $entries]));
        clearstatcache();
    }

    private function reportReservation(string $status = 'ready_for_pickup', string $fee = '0.10'): Reservation
    {
        $record = $this->reportResident->reservations()->create(['service_id' => $this->reportService->id,
            'schedule_id' => $this->reportSchedule->id, 'status' => $status]);
        $record->forceFill(['fee_snapshot' => $fee, 'created_at' => $this->eventTime])->save();

        return $record->fresh();
    }

    private function reportPayment(Reservation $record, ?string $amount = null): void
    {
        (new ReservationPayment)->forceFill(['reservation_id' => $record->id, 'amount' => $amount ?? $record->fee_snapshot,
            'paid_at' => $this->eventTime, 'recorded_by' => $this->reportAdmin->id])->save();
    }

    private function reportArrival(Reservation $record): void
    {
        $ticket = app(QrTicketIssuer::class)->issue($record);
        $checkins = app(CheckInReservations::class);
        $checkins->record($record, $this->reportAdmin, $checkins->evidence($ticket, $this->reportAdmin));
    }

    private function reportDocument(Reservation $record, bool $valid = true): void
    {
        $bytes = CertificatePdf::bytes('Synthetic report certificate');
        $path = 'reservation-documents/'.$record->id.'/'.Str::random(40).'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $record->document()->create(['mime_type' => $valid ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'stored_path' => $path, 'file_size' => strlen($bytes), 'content_hash' => hash('sha256', $bytes),
            'template_revision' => str_repeat('b', 64), 'template_version' => 1,
            'template_filename' => 'Synthetic.pdf', 'original_filename' => 'Synthetic.pdf',
            'generated_at' => $this->eventTime, 'generated_by' => $this->reportAdmin->id]);
    }

    private function seedSharedPopulation(): array
    {
        $records = [];
        foreach (ReservationStatus::cases() as $status) {
            $records[$status->value] = $this->reportReservation($status->value, match ($status) {
                ReservationStatus::ReadyForPickup => '0.10', ReservationStatus::Completed => '0.20',
                ReservationStatus::Approved => '0.31', default => '0.00',
            });
        }
        $this->reportPayment($records['ready_for_pickup']);
        $this->reportPayment($records['completed']);
        $this->reportPayment($records['approved'], '0.30'); // Deliberate integrity-warning fixture.
        $this->reportArrival($records['ready_for_pickup']);
        $payload = $records['ready_for_pickup']->fresh()->qrTicket->encodedPayload();
        $this->assertTrue(app(QrTicketVerifier::class)->verify($payload)['valid']);
        $this->assertTrue(app(QrTicketVerifier::class)->verify($payload)['valid']);
        CheckinLog::create(['reservation_id' => null, 'verification_result' => 'checked_in',
            'verified_at' => $this->eventTime, 'verified_by' => $this->reportAdmin->id]);
        $this->reportDocument($records['completed']);
        $this->reportDocument($records['pending'], false);
        Storage::disk('local')->put('document-previews/synthetic-preview.pdf', CertificatePdf::bytes());
        $excluded = $this->reportReservation('ready_for_pickup', '999.00');
        $this->reportPayment($excluded);
        $this->reportArrival($excluded);
        $this->reportDocument($excluded);
        $this->reviewManifest([$excluded]);
        $this->reportService->update(['is_active' => false]);

        return $records;
    }

    private function csvRows(string $kind, array $filters = []): array
    {
        $response = TestResponse::fromBaseResponse(app(ReportCsvExports::class)->download($kind,
            [...$this->filters, ...$filters], $this->period));
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertSame(strlen($content), (int) $response->headers->get('Content-Length'));
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        fgetcsv($stream, escape: '');
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
