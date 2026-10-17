<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CheckInReservations;
use App\Services\DocumentTemplateConfiguration;
use App\Services\GeneratedReservationDocuments;
use App\Services\QrTicketIssuer;
use App\Services\ReservationDocumentInformation;
use App\Services\ReservationNotifications;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class ReservationNotificationsTest extends TestCase
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
        $this->withoutVite();
    }

    private function user(UserRole $role = UserRole::Resident, array $attributes = []): User
    {
        return User::factory()->create([...['role' => $role, 'is_active' => true], ...$attributes]);
    }

    private function fixture(string $status = 'pending', ?string $fee = '50.00'): Reservation
    {
        $r = $this->user()->reservations()->make([
            'service_id' => Service::create(['name' => 'Barangay Clearance', 'fee_amount' => '999.00'])->id,
            'schedule_id' => (Schedule::first() ?? Schedule::create(['date' => '2026-12-25', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10]))->id,
            'status' => $status,
        ]);
        $r->forceFill(['fee_snapshot' => $fee])->save();

        return $r;
    }

    private function submit(Reservation $fixture): void
    {
        $this->actingAs($fixture->user);
        $this->post('/resident/reservations/service', ['service_id' => $fixture->service_id])->assertRedirect();
        $this->post('/resident/reservations/requirements')->assertRedirect();
        $this->post('/resident/reservations/schedule', ['schedule_id' => $fixture->schedule_id])->assertRedirect();
        $this->post('/resident/reservations', ['confirmation_token' => session('reservation_wizard.confirmation_token'),
            'recipient_id' => 9999, 'notification_type' => 'admin.new_reservation'])->assertRedirect(route('resident.home'))->assertSessionHasNoErrors();
    }

    public function test_submission_fans_out_only_to_eligible_admins_and_replay_is_silent(): void
    {
        $r = $this->fixture('completed');
        $admins = [$this->user(UserRole::Admin), $this->user(UserRole::Admin)];
        $inactive = $this->user(UserRole::Admin, ['is_active' => false]);
        $unverified = $this->user(UserRole::Admin, ['email_verified_at' => null]);
        $other = $this->user();
        $this->submit($r);
        $this->assertDatabaseCount('notifications', 3);
        $resident = $r->user->notifications()->sole();
        $this->assertSame('resident.request_submitted', $resident->type);
        $this->assertSame('Request Submitted', $resident->data['title']);
        $this->assertNull($resident->read_at);
        foreach ($admins as $admin) {
            $notification = $admin->notifications()->sole();
            $this->assertSame('admin.new_reservation', $notification->type);
            $this->assertSame('New Reservation', $notification->data['title']);
            $this->assertNotSame($resident->data['message'], $notification->data['message']);
        }
        foreach ([$inactive, $unverified, $other] as $user) {
            $this->assertSame(0, $user->notifications()->count());
        }
        $this->post('/resident/reservations', ['confirmation_token' => 'replay'])->assertRedirect();
        $this->assertDatabaseCount('notifications', 3);
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_submission_succeeds_without_any_eligible_admin_and_does_not_backfill(): void
    {
        $r = $this->fixture('completed');
        $this->assertDatabaseCount('notifications', 0);
        $this->submit($r);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotEquals($r->id, $r->user->notifications()->sole()->data['reservation_id']);
    }

    public static function transitions(): array
    {
        return [['pending', 'under_review', 'Request Under Review'], ['under_review', 'approved', 'Request Approved'],
            ['approved', 'ready_for_pickup', 'Ready for Pickup'], ['ready_for_pickup', 'completed', 'Request Completed'],
            ['pending', 'rejected', 'Request Rejected']];
    }

    #[DataProvider('transitions')]
    public function test_successful_status_only_notifies_resident_and_cannot_replay(string $from, string $to, string $title): void
    {
        $r = $this->fixture($from, '0.00');
        $admin = $this->user(UserRole::Admin);
        if ($from === 'ready_for_pickup') {
            app(QrTicketIssuer::class)->issue($r);
        }
        $this->actingAs($admin);
        $payload = ['expected_status' => $from, 'status' => $to, 'notes' => 'INTERNAL SECRET NOTE', 'confirm_ready' => '1'];
        $this->patch(route('admin.reservations.status', $r), $payload)->assertSessionHasNoErrors();
        $notification = $r->user->notifications()->sole();
        $this->assertSame($title, $notification->data['title']);
        $this->assertStringNotContainsString('INTERNAL', json_encode($notification->data));
        $this->assertSame(0, $admin->notifications()->count());
        $this->patch(route('admin.reservations.status', $r), $payload)->assertSessionHasErrors('status');
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $r->statusHistories()->count());
        if ($to === 'approved') {
            $this->assertNotNull($r->qrTicket);
            $this->assertStringNotContainsString($r->qrTicket->ticket_code, json_encode($notification->data));
        }
        if ($to === 'completed') {
            $this->assertNotNull($r->qrTicket->used_at);
        }
        if ($to === 'ready_for_pickup') {
            foreach (['Paid', 'Checked In', 'Completed'] as $word) {
                $this->assertStringNotContainsString($word, $notification->data['message']);
            }
        }
    }

    public function test_payment_uses_saved_amount_and_does_not_complete_or_notify_admin(): void
    {
        $r = $this->fixture('ready_for_pickup', '50.00');
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->post(route('admin.reservations.payment.store', $r), ['confirm_payment' => 1])->assertSessionHasNoErrors();
        $notification = $r->user->notifications()->sole();
        $this->assertSame('resident.payment_recorded', $notification->type);
        $this->assertStringContainsString('₱50.00', $notification->data['message']);
        $this->assertStringNotContainsString('999', $notification->data['message']);
        $this->assertSame('ready_for_pickup', $r->fresh()->status->value);
        $this->assertSame(0, $admin->notifications()->count());
        $this->post(route('admin.reservations.payment.store', $r), ['confirm_payment' => 1])->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_cancellation_notifies_both_roles_once_and_never_shares_reason(): void
    {
        $r = $this->fixture();
        $admin = $this->user(UserRole::Admin);
        $inactive = $this->user(UserRole::Admin, ['is_active' => false]);
        $unverified = $this->user(UserRole::Admin, ['email_verified_at' => null]);
        $this->actingAs($r->user)->post(route('resident.reservations.cancel', $r), ['reason' => 'PRIVATE REASON'])->assertSessionHasNoErrors();
        $this->assertSame('resident.request_cancelled', $r->user->notifications()->sole()->type);
        $this->assertSame('admin.resident_cancelled', $admin->notifications()->sole()->type);
        $this->assertSame(0, $inactive->notifications()->count() + $unverified->notifications()->count());
        $this->assertStringNotContainsString('PRIVATE REASON', DB::table('notifications')->pluck('data')->implode(''));
        $this->post(route('resident.reservations.cancel', $r), ['reason' => 'Again'])->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('notifications', 2);
    }

    public static function failureEvents(): array
    {
        return [['submission'], ['approval'], ['payment'], ['completion'], ['cancellation']];
    }

    #[DataProvider('failureEvents')]
    public function test_notification_persistence_failure_rolls_back_entire_business_event(string $event): void
    {
        $r = $this->fixture(match ($event) {
            'approval' => 'under_review', 'payment', 'completion' => 'ready_for_pickup', 'submission' => 'completed', default => 'pending'
        }, '0.00');
        if ($event === 'payment') {
            $r->forceFill(['fee_snapshot' => '50.00'])->save();
        }
        $admin = $this->user(UserRole::Admin);
        $before = $r->fresh()->getRawOriginal();
        DatabaseNotification::creating(fn () => throw new RuntimeException('Simulated notification failure'));
        $this->withoutExceptionHandling();
        try {
            match ($event) {
                'submission' => $this->submit($r),
                'payment' => $this->actingAs($admin)->post(route('admin.reservations.payment.store', $r), ['confirm_payment' => 1]),
                'cancellation' => $this->actingAs($r->user)->post(route('resident.reservations.cancel', $r), ['reason' => 'Cancel']),
                default => $this->actingAs($admin)->patch(route('admin.reservations.status', $r), ['expected_status' => $r->status->value, 'status' => $event === 'approval' ? 'approved' : 'completed']),
            };
            $this->fail('Expected notification failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated notification failure', $e->getMessage());
        } finally {
            DatabaseNotification::flushEventListeners();
        }
        $this->assertSame($before, $r->fresh()->getRawOriginal());
        foreach (['notifications', 'reservation_status_histories', 'reservation_payments', 'qr_tickets'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_event_dedup_keeps_frozen_text_read_state_and_unknown_events_are_silent(): void
    {
        $r = $this->fixture();
        $policy = app(ReservationNotifications::class);
        DB::transaction(fn () => $policy->record('submitted', $r));
        $notification = $r->user->notifications()->sole();
        $notification->markAsRead();
        $original = $notification->fresh()->getRawOriginal();
        $r->service->update(['name' => 'Changed Service', 'fee_amount' => '1.00']);
        $r->user->update(['name' => 'Changed Resident']);
        DB::transaction(fn () => $policy->record('submitted', $r->fresh()));
        foreach (['qr_verified', 'checked_in', 'document_finalized', 'unknown'] as $event) {
            $policy->record($event, $r);
        }
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($original, $notification->fresh()->getRawOriginal());
        $duplicate = $notification->replicate();
        $duplicate->id = (string) Str::uuid();
        $this->expectException(UniqueConstraintViolationException::class);
        $duplicate->save();
    }

    public function test_partial_admin_fanout_cannot_commit_when_a_later_recipient_fails(): void
    {
        $r = $this->fixture('completed');
        $this->user(UserRole::Admin);
        $this->user(UserRole::Admin);
        $created = 0;
        DatabaseNotification::creating(function () use (&$created) {
            if (++$created === 3) {
                throw new RuntimeException('Later recipient failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->submit($r);
            $this->fail('Expected fan-out failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Later recipient failure', $e->getMessage());
        } finally {
            DatabaseNotification::flushEventListeners();
        }
        $this->assertSame(3, $created);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_unpaid_completion_and_free_legacy_payment_fail_without_notifications(): void
    {
        $admin = $this->user(UserRole::Admin);
        $r = $this->fixture('ready_for_pickup');
        app(QrTicketIssuer::class)->issue($r);
        $qr = $r->qrTicket->getRawOriginal();
        $this->actingAs($admin)->patch(route('admin.reservations.status', $r), ['expected_status' => 'ready_for_pickup', 'status' => 'completed'])->assertSessionHasErrors('status');
        $this->assertSame('ready_for_pickup', $r->fresh()->status->value);
        $this->assertSame($qr, $r->qrTicket->fresh()->getRawOriginal());
        foreach (['0.00', null] as $fee) {
            $free = $this->fixture('ready_for_pickup', $fee);
            $this->post(route('admin.reservations.payment.store', $free), ['confirm_payment' => 1])->assertSessionHasErrors('payment');
        }
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public function test_qr_verification_and_checkin_are_silent_and_do_not_consume_qr(): void
    {
        $r = $this->fixture('ready_for_pickup');
        $admin = $this->user(UserRole::Admin);
        $ticket = app(QrTicketIssuer::class)->issue($r);
        $this->actingAs($admin)->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload()])->assertJsonPath('valid', true);
        $this->postJson('/admin/qr-verification', ['payload' => 'invalid'])->assertJsonPath('valid', false);
        app(CheckInReservations::class)->record($r, $admin, app(CheckInReservations::class)->evidence($ticket, $admin));
        $this->get(route('admin.reservations.show', $r))->assertOk();
        $this->get(route('admin.reservation-logs.index'))->assertOk();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('checkin_logs', 1);
        $this->assertNull($ticket->fresh()->used_at);
        $this->assertSame('ready_for_pickup', $r->fresh()->status->value);
    }

    public static function failedSubmissions(): array
    {
        return [['duplicate'], ['capacity'], ['validation'], ['token']];
    }

    #[DataProvider('failedSubmissions')]
    public function test_failed_submission_creates_no_notifications(string $failure): void
    {
        $r = $this->fixture($failure === 'duplicate' ? 'pending' : 'completed');
        $this->actingAs($r->user);
        $this->post('/resident/reservations/service', ['service_id' => $r->service_id]);
        $this->post('/resident/reservations/requirements');
        $this->post('/resident/reservations/schedule', ['schedule_id' => $r->schedule_id]);
        if ($failure === 'capacity') {
            $r->schedule->update(['capacity' => 1]);
            $other = $this->user();
            $other->reservations()->create(['service_id' => $r->service_id, 'schedule_id' => $r->schedule_id, 'status' => 'pending']);
        }
        $payload = $failure === 'validation' ? [] : ['confirmation_token' => $failure === 'token' ? 'wrong' : session('reservation_wizard.confirmation_token')];
        $before = Reservation::count();
        $this->post('/resident/reservations', $payload)->assertRedirect();
        $this->assertSame($before, Reservation::count());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_preview_finalization_print_and_download_are_silent(): void
    {
        $r = $this->fixture('approved', '0.00');
        $admin = $this->user(UserRole::Admin);
        $service = $r->service;
        $bytes = CertificatePdf::bytes();
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('a', 40).'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $master = $service->documentTemplate()->create(['original_filename' => 'Official.pdf', 'stored_path' => $path,
            'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'uploaded_at' => now()]);
        app(DocumentTemplateConfiguration::class)->create($service, ['pdf_id' => $master->id, 'pdf_revision' => $master->revision(), 'is_active' => true], $admin->id);
        $template = $service->dynamicDocumentTemplate()->first();
        $field = $template->fields()->create(['field_name' => 'resident_name', 'source' => 'system', 'label' => 'Name', 'field_type' => 'text']);
        $field->positions()->create(['page_number' => 1, 'x_position' => 40, 'y_position' => 90, 'width' => 230, 'height' => 30, 'font_size' => 12, 'alignment' => 'left', 'text_behavior' => 'wrap']);
        $information = app(ReservationDocumentInformation::class);
        $information->store($r, $template->load('fields'), [], $information->profile($r->user));
        $this->actingAs($admin);
        $artifact = $this->get(route('admin.reservations.document-preview.show', $r))->assertOk()->viewData('artifact');
        $reviewed = $this->get(route('admin.reservations.document-preview.pdf', [$r, 'preview' => $artifact['token']]))->assertOk()->getContent();
        $this->post(route('admin.reservations.generated.store', $r), ['preview_token' => $artifact['token']])->assertSessionHasNoErrors();
        $this->assertSame($reviewed, app(GeneratedReservationDocuments::class)->bytes($r->fresh()));
        $this->get(route('admin.reservations.generated.download', $r))->assertOk()->assertContent($reviewed);
        $url = $this->get(route('admin.reservations.document.print', $r))->assertRedirect()->headers->get('Location');
        $this->get($url)->assertOk();
        $this->get($url.'/pdf')->assertOk()->assertContent($reviewed);
        $this->get(route('admin.services.template.preview', $service))->assertOk();
        $this->get(route('admin.services.template.download', $service))->assertOk();
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $this->assertSame('approved', $r->fresh()->status->value);
    }
}
