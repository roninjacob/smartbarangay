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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MarkAsMissedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true,
            'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
        Storage::fake('local');
        // 2026-10-28 14:00 Manila: the 2026-10-27 09:00-10:00 appointment has ended.
        $this->travelTo(now('UTC')->setDate(2026, 10, 28)->setTime(6, 0));
    }

    /** Ready for Pickup since 2026-10-26, appointment ended 2026-10-27 10:00 Manila, QR issued, no check-in. */
    private function fixture(string $status = 'ready_for_pickup', string $readyAt = '2026-10-26 01:00:00'): array
    {
        $owner = User::factory()->residencyApproved()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $service = Service::firstOrCreate(['name' => 'Missed visit test'], ['fee_amount' => '0.00', 'is_active' => true]);
        $schedule = Schedule::whereDate('date', '2026-10-27')->first()
            ?? Schedule::create(['date' => '2026-10-27', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 1]);
        $reservation = $owner->reservations()->make(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
        $reservation->forceFill(['fee_snapshot' => '0.00', 'appointment_date' => '2026-10-27',
            'appointment_start_time' => '09:00:00', 'appointment_end_time' => '10:00:00'])->save();
        app(QrTicketIssuer::class)->issue($reservation);
        if ($status === 'ready_for_pickup') {
            $reservation->statusHistories()->create(['from_status' => 'approved', 'to_status' => 'ready_for_pickup',
                'changed_by' => $admin->id, 'changed_at' => $readyAt]);
        }

        return [$owner, $admin, $reservation->refresh()];
    }

    private function markMissed(User $admin, Reservation $r, array $overrides = [])
    {
        return $this->actingAs($admin)->post(route('admin.reservations.mark-missed.store', $r),
            [...['expected_status' => $r->fresh()->status->value, 'confirm_mark_missed' => '1'], ...$overrides]);
    }

    public function test_mark_as_missed_records_review_cancels_and_uses_existing_cancellation_notification(): void
    {
        [$owner, $admin, $r] = $this->fixture();
        $this->markMissed($admin, $r)->assertRedirect(route('admin.reservations.show', $r))->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Marked as missed. The request is cancelled and the slot is free.');

        $r->refresh();
        $this->assertSame(ReservationStatus::Cancelled, $r->status);
        $review = $r->attendanceReview()->sole();
        $this->assertSame($admin->id, $review->recorded_by);
        $this->assertSame('Missed appointment', $review->notes);
        $history = $r->statusHistories()->latest('id')->first();
        $this->assertSame(ReservationStatus::ReadyForPickup, $history->from_status);
        $this->assertSame(ReservationStatus::Cancelled, $history->to_status);
        $this->assertSame('Missed appointment', $history->notes);
        $this->assertSame($admin->id, $history->changed_by);
        $this->assertSame(2, $r->statusHistories()->count());
        // The existing "cancelled" notification, not a new type.
        $notification = $owner->notifications()->sole();
        $this->assertSame('resident.request_cancelled', $notification->type);
        $this->assertSame('status:'.$history->id, $notification->event_key);
        // Office-initiated wording on the same type and key.
        $this->assertSame('Your Missed visit test request #'.$r->id.' was cancelled because the pickup appointment was missed. You can submit a new request if you still need it.',
            $notification->data['message']);
        $adminNotice = $admin->notifications()->sole();
        $this->assertSame('admin.resident_cancelled', $adminNotice->type);
        $this->assertSame('status:'.$history->id, $adminNotice->event_key);
        $this->assertSame('Missed visit test request #'.$r->id.' was cancelled as a missed appointment.', $adminNotice->data['message']);
        $this->assertStringNotContainsString('cancelled successfully', $notification->data['message']);
        $this->assertStringNotContainsString($owner->name.' cancelled', $adminNotice->data['message']);
    }

    public function test_resident_initiated_cancellations_keep_the_original_wording(): void
    {
        // Approved cancellation request on a Ready for Pickup request that already has an old missed review.
        [$owner, $admin, $r] = $this->fixture();
        app(ReservationFollowups::class)->recordMissed($admin, $r->id, 'Recorded earlier with the old button');
        $this->actingAs($owner)->post(route('resident.reservations.cancellation-requests.store', $r), ['reason' => 'No longer needed', 'confirm_request' => '1'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.reservations.cancellation-requests.review', [$r, $r->cancellationRequests()->sole()->id]),
            ['decision' => 'approved', 'decision_message' => 'Approved as requested', 'expected_status' => 'ready_for_pickup', 'confirm_review' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame(ReservationStatus::Cancelled, $r->fresh()->status);
        $this->assertSame('Your Missed visit test request #'.$r->id.' was cancelled successfully.',
            $owner->notifications()->where('type', 'resident.request_cancelled')->sole()->data['message']);
        $this->assertSame($owner->name.' cancelled Missed visit test request #'.$r->id.'.',
            $admin->notifications()->where('type', 'admin.resident_cancelled')->sole()->data['message']);

        // Resident cancels a Pending request, even with a reason that matches the missed note.
        $pending = $owner->reservations()->make(['service_id' => $r->service_id, 'schedule_id' => $r->schedule_id, 'status' => 'pending']);
        $pending->forceFill(['fee_snapshot' => '0.00', 'appointment_date' => '2026-10-27',
            'appointment_start_time' => '09:00:00', 'appointment_end_time' => '10:00:00'])->save();
        $this->actingAs($owner)->post(route('resident.reservations.cancel', $pending), ['reason' => ReservationFollowups::MISSED])->assertSessionHasNoErrors();
        $this->assertSame(ReservationStatus::Cancelled, $pending->fresh()->status);
        $this->assertSame('Your Missed visit test request #'.$pending->id.' was cancelled successfully.',
            $owner->notifications()->where('type', 'resident.request_cancelled')->where('data->reservation_id', $pending->id)->sole()->data['message']);
        $this->assertSame($owner->name.' cancelled Missed visit test request #'.$pending->id.'.',
            $admin->notifications()->where('type', 'admin.resident_cancelled')->where('data->reservation_id', $pending->id)->sole()->data['message']);
    }

    public function test_existing_missed_review_is_reused_without_a_duplicate(): void
    {
        [, $admin, $r] = $this->fixture();
        app(ReservationFollowups::class)->recordMissed($admin, $r->id, 'Recorded earlier with the old button');
        $this->assertSame(ReservationStatus::ReadyForPickup, $r->fresh()->status);

        $this->markMissed($admin, $r)->assertSessionHasNoErrors();
        $this->assertSame(ReservationStatus::Cancelled, $r->fresh()->status);
        $this->assertSame(1, $r->attendanceReview()->count());
        $this->assertSame('Recorded earlier with the old button', $r->attendanceReview()->sole()->notes);
        $this->assertSame('Missed appointment', $r->statusHistories()->latest('id')->first()->notes);
    }

    public static function blockedCases(): array
    {
        return [
            'appointment not over' => ['future', 'Available after the appointment ends.'],
            'check-in recorded' => ['checked_in', 'Check-in is already recorded.'],
            'not ready for pickup' => ['approved', 'Available only for a Ready for Pickup request.'],
            'ready after appointment' => ['ready_late', 'It was not ready before the appointment ended.'],
            'legacy without snapshot' => ['legacy', 'This request has no preserved appointment.'],
            'payment recorded' => ['payment', 'A payment is recorded.'],
            'finalized certificate' => ['document', 'A finalized certificate exists.'],
        ];
    }

    #[DataProvider('blockedCases')]
    public function test_ineligible_requests_are_refused_without_changes_and_hide_the_action(string $case, string $reason): void
    {
        [$owner, $admin, $r] = $this->fixture($case === 'approved' ? 'approved' : 'ready_for_pickup',
            $case === 'ready_late' ? '2026-10-28 01:00:00' : '2026-10-26 01:00:00');
        match ($case) {
            'future' => $this->travelTo(now('UTC')->subDays(3)),
            'checked_in' => $r->checkinLogs()->create(['verification_result' => CheckinLog::ARRIVAL, 'verified_at' => now(), 'verified_by' => $admin->id]),
            'legacy' => DB::table('reservations')->where('id', $r->id)->update(['appointment_date' => null]),
            'payment' => (new ReservationPayment)->forceFill(['reservation_id' => $r->id, 'amount' => '50.00', 'paid_at' => now(), 'recorded_by' => $admin->id])->save(),
            'document' => (new ReservationDocument)->forceFill(['reservation_id' => $r->id, 'template_revision' => str_repeat('a', 64), 'template_filename' => 'master.pdf',
                'stored_path' => 'private/unchanged.pdf', 'original_filename' => 'saved.pdf', 'mime_type' => 'application/pdf', 'file_size' => 20,
                'generated_at' => now(), 'generated_by' => $admin->id])->save(),
            default => null,
        };
        $r->refresh();
        $status = $r->status;
        $histories = $r->statusHistories()->count();

        $this->assertSame($reason, app(ReservationFollowups::class)->markMissedBlock($r));
        $page = $this->actingAs($admin)->get(route('admin.reservations.show', $r))->assertOk()->assertDontSee('confirm_mark_missed', false);
        if ($status === ReservationStatus::ReadyForPickup) {
            $page->assertSee('Mark as missed: '.$reason);
        }
        $this->markMissed($admin, $r)->assertSessionHasErrors('followup');

        $this->assertSame($status, $r->fresh()->status);
        $this->assertSame($histories, $r->statusHistories()->count());
        $this->assertSame(0, $r->attendanceReview()->count());
        $this->assertSame(0, $owner->notifications()->count());
    }

    public function test_only_admins_can_mark_missed_and_confirmation_and_expected_status_are_required(): void
    {
        [$owner, $admin, $r] = $this->fixture();
        $this->post(route('admin.reservations.mark-missed.store', $r), ['expected_status' => 'ready_for_pickup', 'confirm_mark_missed' => '1'])
            ->assertRedirect(route('login'));
        $this->actingAs($owner)->post(route('admin.reservations.mark-missed.store', $r), ['expected_status' => 'ready_for_pickup', 'confirm_mark_missed' => '1'])
            ->assertForbidden();
        $other = User::factory()->residencyApproved()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $this->actingAs($other)->post(route('admin.reservations.mark-missed.store', $r), ['expected_status' => 'ready_for_pickup', 'confirm_mark_missed' => '1'])
            ->assertForbidden();
        $this->markMissed($admin, $r, ['confirm_mark_missed' => null])->assertSessionHasErrors('confirm_mark_missed');
        $this->markMissed($admin, $r, ['expected_status' => 'approved'])->assertSessionHasErrors('followup');

        $this->assertSame(ReservationStatus::ReadyForPickup, $r->fresh()->status);
        $this->assertSame(0, $r->attendanceReview()->count());
        $this->assertSame(1, $r->statusHistories()->count());
        $this->assertSame(0, $owner->notifications()->count());
    }

    public function test_double_click_creates_one_history_review_and_notification(): void
    {
        [$owner, $admin, $r] = $this->fixture();
        $this->markMissed($admin, $r)->assertSessionHasNoErrors();
        // A second click from the same stale page still carries expected_status=ready_for_pickup.
        $this->markMissed($admin, $r, ['expected_status' => 'ready_for_pickup'])->assertSessionHasErrors('followup');
        try {
            app(ReservationFollowups::class)->markMissed($admin, $r->id, 'ready_for_pickup');
            $this->fail('A repeated mark must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(['The request status changed. Reload the request.'], $exception->errors()['followup']);
        }

        $this->assertSame(ReservationStatus::Cancelled, $r->fresh()->status);
        $this->assertSame(1, $r->statusHistories()->where('to_status', ReservationStatus::Cancelled)->count());
        $this->assertSame(1, $r->attendanceReview()->count());
        $this->assertSame(1, $owner->notifications()->count());
    }

    public function test_notification_failure_rolls_back_review_status_and_history(): void
    {
        [, $admin, $r] = $this->fixture();
        $this->mock(ReservationNotifications::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic notification failure'));
        try {
            app(ReservationFollowups::class)->markMissed($admin, $r->id, 'ready_for_pickup');
            $this->fail('Failure should propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic notification failure', $exception->getMessage());
        }
        $this->assertSame(ReservationStatus::ReadyForPickup, $r->fresh()->status);
        $this->assertSame(0, $r->attendanceReview()->count());
        $this->assertSame(1, $r->statusHistories()->count());
    }

    public function test_qr_ticket_cannot_be_verified_or_checked_in_afterwards_and_audit_fields_are_untouched(): void
    {
        [, $admin, $r] = $this->fixture();
        $ticket = $r->qrTicket;
        $this->assertTrue(app(QrTicketVerifier::class)->verify($ticket->encodedPayload())['valid']);
        $evidence = app(CheckInReservations::class)->evidence($ticket, $admin);
        $before = $ticket->getAttributes();

        $this->markMissed($admin, $r)->assertSessionHasNoErrors();

        $result = app(QrTicketVerifier::class)->verify($ticket->encodedPayload());
        $this->assertFalse($result['valid']);
        $this->assertSame('not_eligible', $result['reason']);
        try {
            app(CheckInReservations::class)->record($r->fresh(), $admin, $evidence);
            $this->fail('Check-in must be refused for a cancelled request.');
        } catch (ValidationException) {
        }
        $this->assertSame(0, $r->checkinLogs()->count());
        $this->assertSame($before, $r->qrTicket()->first()->getAttributes());
        $this->assertNull($r->payment()->first());
        $this->assertNull($r->document()->first());
    }

    public function test_slot_is_released_and_resident_can_request_the_same_service_again(): void
    {
        [$owner, $admin, $r] = $this->fixture();
        $schedule = $r->schedule;
        $this->assertSame(0, $schedule->remainingSlots());
        $this->assertTrue($owner->reservations()->occupyingSlot()->where('service_id', $r->service_id)->where('schedule_id', $schedule->id)->exists());

        $this->markMissed($admin, $r)->assertSessionHasNoErrors();

        $this->assertSame(1, $schedule->fresh()->remainingSlots());
        $this->assertFalse($owner->reservations()->occupyingSlot()->where('service_id', $r->service_id)->where('schedule_id', $schedule->id)->exists());

        // Resident view: cancelled status and the cancellation notification.
        $this->actingAs($owner)->get(route('resident.reservations.show', $r))->assertOk()->assertSee('Cancelled')
            ->assertSee('The request was cancelled and its slot was released.')
            ->assertSee('This request was cancelled. It will no longer be processed.')->assertDontSee('You cancelled this request.');
        $this->get(route('resident.notifications.index'))->assertOk()->assertSee('Request Cancelled');

        // A new request for the same service goes through the normal wizard.
        $next = Schedule::create(['date' => '2026-10-30', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 1, 'is_active' => true]);
        $this->post(route('resident.reservations.service'), ['service_id' => $r->service_id])->assertRedirect();
        $this->post(route('resident.reservations.requirements'), ['requirement_signature' => $this->requirementSignature()])->assertRedirect();
        $this->post(route('resident.reservations.schedule.select'), ['schedule_id' => $next->id])->assertRedirect(route('resident.reservations.confirm'));
        $this->post(route('resident.reservations.store'), ['confirmation_token' => session('reservation_wizard.confirmation_token')])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $owner->reservations()->where('service_id', $r->service_id)->count());
        $this->assertSame(ReservationStatus::Pending, $owner->reservations()->latest('id')->first()->status);
    }

    public function test_admin_page_shows_mark_as_missed_and_hides_the_old_button(): void
    {
        [$owner, $admin, $r] = $this->fixture();
        $this->actingAs($admin)->get(route('admin.reservations.show', $r))->assertOk()
            ->assertSee(route('admin.reservations.mark-missed.store', $r), false)
            ->assertSee('Cancels this request and frees the slot.')
            ->assertDontSee(route('admin.reservations.missed.store', $r), false)
            ->assertDontSee('Record missed appointment');
        // Residents never see the admin action.
        $this->actingAs($owner)->get(route('resident.reservations.show', $r))->assertOk()
            ->assertDontSee('confirm_mark_missed', false)->assertDontSee('Mark as missed');
    }

    public function test_old_record_missed_route_is_unchanged_and_mark_as_missed_follows(): void
    {
        [, $admin, $r] = $this->fixture();
        $this->actingAs($admin)->post(route('admin.reservations.missed.store', $r), ['notes' => 'Office confirmed absence', 'confirm_missed' => 1])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Missed appointment confirmed. Processing status, payment, QR and document remain unchanged.');
        $this->assertSame(ReservationStatus::ReadyForPickup, $r->fresh()->status);
        $this->assertSame(1, $r->statusHistories()->count());
        $this->actingAs($admin)->get(route('admin.reservations.show', $r))->assertOk()
            ->assertSee('Missed appointment confirmed by the office.')
            ->assertSee(route('admin.reservations.mark-missed.store', $r), false);
    }

    public function test_past_appointment_list_shows_the_action_only_for_eligible_rows(): void
    {
        [, $admin, $eligible] = $this->fixture();
        [, , $late] = $this->fixture('ready_for_pickup', '2026-10-28 01:00:00');
        $this->actingAs($admin)->get(route('admin.reservations.index', ['followup' => 'past_appointment']))->assertOk()
            ->assertSee(route('admin.reservations.mark-missed.store', $eligible), false)
            ->assertDontSee(route('admin.reservations.mark-missed.store', $late), false)
            ->assertSee('Mark as missed: It was not ready before the appointment ended.');
        $this->get(route('admin.reservations.index'))->assertOk()->assertDontSee('confirm_mark_missed', false);

        $this->markMissed($admin, $eligible)->assertSessionHasNoErrors();
        $this->get(route('admin.reservations.index', ['followup' => 'past_appointment']))->assertOk()
            ->assertDontSee(route('admin.reservations.mark-missed.store', $eligible), false);
    }
}
