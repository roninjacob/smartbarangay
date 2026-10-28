<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\CheckinLog;
use App\Models\Reservation;
use App\Models\ReservationDocument;
use App\Models\ReservationPayment;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CheckInReservations;
use App\Services\QrTicketIssuer;
use App\Services\QrTicketVerifier;
use App\Services\ReservationFollowups;
use App\Services\ReservationNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReservationFollowupsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true,
            'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Storage::fake('local');
        $this->travelTo(now('UTC')->setDate(2026, 10, 28)->setTime(6, 0));
    }

    private function fixture(string $status = 'approved'): array
    {
        $owner = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $service = Service::create(['name' => 'Follow-up test', 'fee_amount' => '50.00']);
        $schedule = Schedule::create(['date' => '2026-10-27', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 5]);
        $reservation = $owner->reservations()->make(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
        $reservation->forceFill(['fee_snapshot' => '50.00', 'appointment_date' => '2026-10-27',
            'appointment_start_time' => '09:00:00', 'appointment_end_time' => '10:00:00'])->save();
        if (in_array($status, ['approved', 'ready_for_pickup'])) {
            app(QrTicketIssuer::class)->issue($reservation);
        }

        return [$owner, $admin, $reservation];
    }

    private function submit(User $owner, Reservation $r)
    {
        return $this->actingAs($owner)->post(route('resident.reservations.cancellation-requests.store', $r),
            ['reason' => 'No longer needed <script>alert(1)</script>', 'confirm_request' => '1']);
    }

    private function review(User $admin, Reservation $r, string $decision = 'approved', ?int $id = null)
    {
        return $this->actingAs($admin)->post(route('admin.reservations.cancellation-requests.review', [$r, $id ?? $r->cancellationRequests()->firstOrFail()->id]),
            ['decision' => $decision, 'decision_message' => 'Office decision <b>reviewed</b>', 'expected_status' => $r->fresh()->status->value, 'confirm_review' => 1]);
    }

    public function test_request_then_approval_is_atomic_preserves_qr_and_rejects_replay(): void
    {
        [$owner, $admin, $r] = $this->fixture();
        $qr = $r->qrTicket->getAttributes();
        $this->submit($owner, $r)->assertSessionHasNoErrors();
        $this->assertSame(ReservationStatus::Approved, $r->fresh()->status);
        $this->assertSame(0, $r->statusHistories()->count());
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());
        $this->submit($owner, $r)->assertSessionHasErrors('followup');
        $this->assertSame(1, $r->cancellationRequests()->count());
        $this->actingAs($admin)->get(route('admin.reservations.show', $r))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->review($admin, $r)->assertSessionHasNoErrors();
        $this->assertSame(ReservationStatus::Cancelled, $r->fresh()->status);
        $this->assertSame(1, $r->statusHistories()->count());
        $this->assertSame($admin->id, $r->statusHistories()->first()->changed_by);
        $this->assertSame(2, $owner->notifications()->count());
        $this->assertSame(2, $admin->notifications()->count());
        $this->assertSame($qr, $r->qrTicket()->first()->getAttributes());
        $this->assertFalse(app(QrTicketVerifier::class)->verify($r->qrTicket->encodedPayload())['valid']);
        $this->review($admin, $r)->assertSessionHasErrors('followup');
        $this->assertSame(1, $r->statusHistories()->count());
        $this->actingAs($owner)->get(route('resident.reservations.show', $r))->assertOk()->assertSee('Office decision &lt;b&gt;reviewed&lt;/b&gt;', false);
    }

    public static function protectedRecords(): array
    {
        return [['payment'], ['document'], ['arrival']];
    }

    private function protect(Reservation $r, User $admin, string $kind): void
    {
        match ($kind) {
            'payment' => (new ReservationPayment)->forceFill(['reservation_id' => $r->id, 'amount' => '50.00', 'paid_at' => now(), 'recorded_by' => $admin->id])->save(),
            'document' => (new ReservationDocument)->forceFill(['reservation_id' => $r->id, 'template_revision' => str_repeat('a', 64), 'template_filename' => 'master.pdf', 'stored_path' => 'private/unchanged.pdf', 'original_filename' => 'saved.pdf', 'mime_type' => 'application/pdf', 'file_size' => 20, 'generated_at' => now(), 'generated_by' => $admin->id])->save(),
            'arrival' => $r->checkinLogs()->create(['verification_result' => CheckinLog::ARRIVAL, 'verified_at' => now(), 'verified_by' => $admin->id]),
        };
    }

    #[DataProvider('protectedRecords')]
    public function test_locked_review_rechecks_new_protected_records_and_decline_still_works(string $kind): void
    {
        [$owner, $admin, $r] = $this->fixture();
        $this->submit($owner, $r)->assertSessionHasNoErrors();
        $this->protect($r, $admin, $kind);
        $this->review($admin, $r)->assertSessionHasErrors('followup');
        $this->assertSame(ReservationStatus::Approved, $r->fresh()->status);
        $this->assertSame(0, $r->statusHistories()->count());
        $this->assertSame('pending', $r->cancellationRequests()->first()->status);
        $this->review($admin, $r, 'declined')->assertSessionHasNoErrors();
        $this->assertSame('declined', $r->cancellationRequests()->first()->status);
        $this->assertSame(ReservationStatus::Approved, $r->fresh()->status);
    }

    public function test_ownership_roles_validation_and_stale_status_fail_closed(): void
    {
        [$owner, $admin, $r] = $this->fixture();
        $other = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $this->post(route('resident.reservations.cancellation-requests.store', $r))->assertRedirect(route('login'));
        $this->actingAs($admin)->post(route('resident.reservations.cancellation-requests.store', $r))->assertForbidden();
        $this->submit($other, $r)->assertNotFound();
        $this->actingAs($owner)->post(route('resident.reservations.cancellation-requests.store', $r), ['reason' => str_repeat('x', 1001)])->assertSessionHasErrors(['reason', 'confirm_request']);
        $this->submit($owner, $r)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('admin.reservations.cancellation-requests.review', [$r, 1]))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.reservations.cancellation-requests.review', [$r, 1]), ['decision' => 'approved', 'decision_message' => 'Allowed', 'confirm_review' => 1, 'expected_status' => 'under_review'])->assertSessionHasErrors('followup');
        $this->assertSame(ReservationStatus::Approved, $r->fresh()->status);
        $this->review($admin, $r, 'approved', 999)->assertNotFound();
        $this->assertSame('pending', $r->cancellationRequests()->first()->status);
    }

    public function test_ineligible_actor_is_rechecked_from_locked_database_row(): void
    {
        [$owner, , $r] = $this->fixture();
        User::whereKey($owner)->update(['is_active' => false]);
        $this->expectException(HttpException::class);
        app(ReservationFollowups::class)->requestCancellation($owner, $r->id, 'Reason');
    }

    public function test_csrf_and_unverified_accounts_cannot_create_followups(): void
    {
        [$owner, $admin, $r] = $this->fixture();
        $this->app['env'] = 'local';
        $this->submit($owner, $r)->assertStatus(419);
        $this->actingAs($admin)->post(route('admin.reservations.missed.store', $r))->assertStatus(419);
        $this->assertDatabaseCount('reservation_cancellation_requests', 0);
        $this->assertDatabaseCount('reservation_attendance_reviews', 0);
        $this->app['env'] = 'testing';
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->submit($owner, $r)->assertRedirect(route('verification.notice'));
    }

    public function test_declined_request_can_be_replaced_without_rewriting_history(): void
    {
        [$owner, $admin, $r] = $this->fixture();
        $this->submit($owner, $r)->assertSessionHasNoErrors();
        $this->review($admin, $r, 'declined')->assertSessionHasNoErrors();
        $first = $r->cancellationRequests()->first()->getAttributes();
        $this->submit($owner, $r)->assertSessionHasNoErrors();
        $this->assertSame(2, $r->cancellationRequests()->count());
        $this->assertSame($first, $r->cancellationRequests()->whereKey($first['id'])->first()->getAttributes());
    }

    public function test_request_rate_limit_does_not_create_duplicate_rows(): void
    {
        [$owner, , $r] = $this->fixture();
        $this->submit($owner, $r)->assertSessionHasNoErrors();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->submit($owner, $r)->assertSessionHasErrors('followup');
        }
        $this->submit($owner, $r)->assertStatus(429);
        $this->assertSame(1, $r->cancellationRequests()->count());
    }

    public function test_notification_failure_rolls_back_cancellation_and_history(): void
    {
        [$owner, $admin, $r] = $this->fixture();
        $this->submit($owner, $r)->assertSessionHasNoErrors();
        $this->mock(ReservationNotifications::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic notification failure'));
        try {
            app(ReservationFollowups::class)->reviewCancellation($admin, $r->id, $r->cancellationRequests()->first()->id, 'approved', 'Reviewed', 'approved');
            $this->fail('Failure should propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic notification failure', $exception->getMessage());
        }
        $this->assertSame(ReservationStatus::Approved, $r->fresh()->status);
        $this->assertSame(0, $r->statusHistories()->count());
        $this->assertSame('pending', $r->cancellationRequests()->first()->status);
    }

    public function test_followup_filters_use_historical_snapshot_and_remain_read_only(): void
    {
        [$owner, $admin, $r] = $this->fixture('ready_for_pickup');
        $this->submit($owner, $r)->assertSessionHasNoErrors();
        $r->schedule->update(['date' => '2026-12-30']);
        $this->actingAs($admin)->get(route('admin.reservations.index', ['followup' => 'cancellation']))->assertOk()->assertSee('Request #'.$r->id);
        $this->get(route('admin.reservations.index', ['followup' => 'past_appointment']))->assertOk()->assertSee('Request #'.$r->id);
        $this->get(route('admin.reservations.index', ['followup' => 'missed']))->assertOk()->assertSee('No matching reservations');
        $this->assertSame(ReservationStatus::ReadyForPickup, $r->fresh()->status);
        $this->assertSame(0, $r->attendanceReview()->count());
    }

    public function test_past_appointment_filter_observes_exact_manila_end_boundary(): void
    {
        [, , $r] = $this->fixture('ready_for_pickup');
        $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00:00', 'Asia/Manila'));
        $query = Reservation::query();
        app(ReservationFollowups::class)->filter($query, 'past_appointment');
        $this->assertFalse($query->whereKey($r)->exists());
        $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00:01', 'Asia/Manila'));
        $query = Reservation::query();
        app(ReservationFollowups::class)->filter($query, 'past_appointment');
        $this->assertTrue($query->whereKey($r)->exists());
    }

    public function test_missed_review_requires_preserved_past_ready_appointment_and_never_consumes_qr(): void
    {
        [, $admin, $r] = $this->fixture('ready_for_pickup');
        $r->statusHistories()->create(['from_status' => 'approved', 'to_status' => 'ready_for_pickup', 'changed_by' => $admin->id, 'changed_at' => '2026-10-26 01:00:00']);
        $qr = $r->qrTicket->getAttributes();
        $this->actingAs($admin)->post(route('admin.reservations.missed.store', $r), ['notes' => 'Office confirmed absence', 'confirm_missed' => 1])->assertSessionHasNoErrors();
        $this->assertSame(ReservationStatus::ReadyForPickup, $r->fresh()->status);
        $this->assertSame(1, $r->statusHistories()->count());
        $this->assertSame($qr, $r->qrTicket()->first()->getAttributes());
        $this->assertTrue(app(QrTicketVerifier::class)->verify($r->qrTicket->encodedPayload())['valid']);
        $evidence = app(CheckInReservations::class)->evidence($r->qrTicket, $admin);
        app(CheckInReservations::class)->record($r, $admin, $evidence);
        $this->assertSame(1, $r->attendanceReview()->count());
        $this->assertSame(1, $r->checkinLogs()->count());
        $this->actingAs($admin)->get(route('admin.reservations.show', $r))->assertOk()->assertSee('Arrived after a recorded missed appointment.');
        $this->actingAs($r->user)->get(route('resident.reservations.show', $r))->assertOk()->assertDontSee('Office confirmed absence');
    }

    public static function unprovenMissed(): array
    {
        return [['future'], ['legacy'], ['not_ready'], ['ready_late'], ['arrived']];
    }

    #[DataProvider('unprovenMissed')]
    public function test_unproven_missed_appointments_cannot_be_recorded(string $case): void
    {
        [, $admin, $r] = $this->fixture($case === 'not_ready' ? 'approved' : 'ready_for_pickup');
        $r->statusHistories()->create(['from_status' => 'approved', 'to_status' => 'ready_for_pickup', 'changed_by' => $admin->id, 'changed_at' => $case === 'ready_late' ? '2026-10-28 01:00:00' : '2026-10-26 01:00:00']);
        if ($case === 'legacy') {
            DB::table('reservations')->where('id', $r->id)->update(['appointment_date' => null]);
        }
        if ($case === 'future') {
            $this->travelTo(now('UTC')->subDays(3));
        }
        if ($case === 'arrived') {
            $this->protect($r, $admin, 'arrival');
        }
        $this->actingAs($admin)->post(route('admin.reservations.missed.store', $r), ['notes' => 'Checked', 'confirm_missed' => 1])->assertSessionHasErrors('followup');
        $this->assertSame(0, $r->attendanceReview()->count());
    }
}
