<?php

namespace Tests\Feature;

use App\Enums\ResidencyStatus;
use App\Enums\UserRole;
use App\Models\DocumentField;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\DocumentTemplateConfiguration;
use App\Services\GeneratedReservationDocuments;
use App\Services\ReservationNotifications;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class EndToEndWorkflowIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->travelTo(Carbon::parse('2026-11-13 10:00:00', 'Asia/Manila'));
        Storage::fake('local');
        Notification::fake();
        $this->withoutVite();
    }

    private function asUser(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user->fresh());
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function identity(): array
    {
        return ['first_name' => 'Synthetic', 'middle_name' => null, 'last_name' => 'Resident', 'suffix' => null,
            'birthdate' => '2000-02-29', 'contact_number' => '09171234567',
            'house_unit_number' => '12', 'address_street' => 'Sample Street', 'address_zone_purok' => 'Sample Zone'];
    }

    private function schedule(): Schedule
    {
        return Schedule::create(['date' => now('Asia/Manila')->addDay()->toDateString(),
            'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 1, 'is_active' => true]);
    }

    private function transition(Reservation $record, string $from, string $to): void
    {
        $this->patch(route('admin.reservations.status', $record), ['status' => $to,
            'expected_status' => $from, 'confirm_ready' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($to, $record->fresh()->status->value);
    }

    public function test_registered_resident_to_reviewed_pdf_manual_arrival_payment_and_completion_preserves_history(): void
    {
        $admin = $this->admin();
        $identity = $this->identity();
        $this->post(route('register.store'), [...$identity, 'email' => 'synthetic-e2e@example.test',
            'password' => 'Synthetic123', 'password_confirmation' => 'Synthetic123', 'residency_status' => 'approved'])
            ->assertRedirect(route('verification.notice'));
        $resident = User::where('email', 'synthetic-e2e@example.test')->sole();
        $this->assertSame(ResidencyStatus::Pending, $resident->residency_status);
        Notification::assertSentTo($resident, VerifyEmail::class);
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addMinutes(30),
            ['id' => $resident->id, 'hash' => sha1($resident->email)]))->assertRedirect(route('resident.home'));
        $this->get(route('resident.home'))->assertOk();
        $this->get(route('resident.reservations.create'))->assertForbidden();
        $this->asUser($admin);
        $this->patch(route('admin.users.residency', $resident), ['residency_status' => 'approved', 'expected_residency_revision' => 0])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('residency_reviews', 1);

        $service = Service::create(['name' => 'Synthetic E2E certificate', 'fee_amount' => '45.50', 'is_active' => true]);
        $masterBytes = CertificatePdf::twoPages();
        $masterPath = 'service-document-templates/'.$service->id.'/'.str_repeat('a', 40).'.pdf';
        Storage::disk('local')->put($masterPath, $masterBytes);
        $master = $service->documentTemplate()->create(['original_filename' => 'Synthetic.pdf', 'stored_path' => $masterPath,
            'mime_type' => 'application/pdf', 'file_size' => strlen($masterBytes), 'uploaded_at' => now()]);
        app(DocumentTemplateConfiguration::class)->create($service, ['pdf_id' => $master->id,
            'pdf_revision' => $master->revision(), 'is_active' => true], $admin->id);
        $template = $service->dynamicDocumentTemplate()->firstOrFail();
        $field = $template->fields()->create(['field_name' => 'resident_name', 'source' => 'system',
            'label' => 'Full name', 'field_type' => 'text', 'is_required' => true]);
        $field->positions()->create(['page_number' => 1, 'x_position' => 40, 'y_position' => 90,
            'width' => 230, 'height' => 30, 'font_size' => 12, 'alignment' => 'left', 'text_behavior' => 'wrap']);
        $this->assertCount(25, DocumentField::SYSTEM_FIELDS);
        $slot = $this->schedule();
        $this->asUser($resident);
        $this->post('/resident/reservations/service', ['service_id' => $service->id])->assertRedirect();
        $form = $this->get(route('resident.reservations.requirements'))->assertOk();
        $this->post('/resident/reservations/requirements', ['requirement_signature' => $this->requirementSignature(),
            'document_signature' => $form->viewData('documentSignature')])->assertSessionHasNoErrors();
        $this->post('/resident/reservations/schedule', ['schedule_id' => $slot->id])->assertSessionHasNoErrors();
        $this->post('/resident/reservations', ['confirmation_token' => session('reservation_wizard.confirmation_token')])
            ->assertRedirect(route('resident.home'))->assertSessionHasNoErrors();
        $record = Reservation::sole();
        $snapshots = [$record->resident_snapshot, $record->document_template_snapshot, $record->appointment_date->toDateString()];
        $this->assertSame('45.50', $record->fee_snapshot);
        $this->assertSame(0, $slot->fresh()->remainingSlots());
        $this->asUser($admin);
        $this->transition($record, 'pending', 'under_review');
        $this->transition($record, 'under_review', 'approved');
        $this->patch(route('admin.reservations.status', $record), ['status' => 'approved', 'expected_status' => 'under_review'])
            ->assertSessionHasErrors('status');
        $this->assertDatabaseCount('qr_tickets', 1);
        $this->assertDatabaseCount('reservation_status_histories', 2);

        $this->asUser($resident);
        $this->put(route('resident.profile.update'), [...$identity, 'address_street' => 'Changed Sample Street'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ResidencyStatus::Pending, $resident->fresh()->residency_status);
        $this->get(route('resident.reservations.create'))->assertForbidden();
        $this->get(route('resident.reservations.show', $record))->assertOk();
        $this->asUser($admin);
        $service->update(['fee_amount' => '999.00']);
        $artifact = $this->get(route('admin.reservations.document-preview.show', $record))->assertOk()->viewData('artifact');
        $reviewedBytes = Storage::disk('local')->get($artifact['path']);
        $finalize = route('admin.reservations.generated.store', $record);
        $this->post($finalize, ['preview_token' => $artifact['token']])->assertSessionHasErrors('document');
        $this->get(route('admin.reservations.document-preview.pdf', [$record, 'preview' => $artifact['token']]))
            ->assertOk()->assertContent($reviewedBytes);
        $this->post($finalize, ['preview_token' => $artifact['token']])->assertSessionHasNoErrors();
        $document = $record->fresh()->document;
        $this->post($finalize, ['preview_token' => $artifact['token']])->assertSessionHasErrors('document');
        $this->assertSame(hash('sha256', $reviewedBytes), $document->content_hash);
        $this->assertSame($reviewedBytes, app(GeneratedReservationDocuments::class)->bytes($record->fresh()));
        $this->transition($record, 'approved', 'ready_for_pickup');
        $payload = $record->fresh()->qrTicket->encodedPayload();
        $this->postJson('/admin/qr-verification', ['payload' => $payload])->assertJsonPath('valid', true);
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->post(route('admin.reservations.check-in.store', $record), ['confirm_checkin' => 1])->assertSessionHasNoErrors();
        $arrival = $record->checkinLogs()->sole()->getAttributes();
        $this->postJson('/admin/qr-verification', ['payload' => $payload])->assertJsonPath('valid', true);
        $this->post(route('admin.reservations.check-in.store', $record), ['confirm_checkin' => 1])->assertSessionHasNoErrors();
        $this->assertSame($arrival, $record->checkinLogs()->sole()->getAttributes());
        $this->patch(route('admin.reservations.status', $record), ['status' => 'completed', 'expected_status' => 'ready_for_pickup'])
            ->assertSessionHasErrors('status');
        $this->assertNull($record->fresh()->qrTicket->used_at);
        $this->post(route('admin.reservations.payment.store', $record), ['confirm_payment' => 1, 'amount' => '999.00'])
            ->assertSessionHasErrors('amount');
        $this->post(route('admin.reservations.payment.store', $record), ['confirm_payment' => 1])->assertSessionHasNoErrors();
        $this->assertSame('45.50', $record->fresh()->payment->amount);
        $this->assertSame('ready_for_pickup', $record->fresh()->status->value);
        $this->post(route('admin.reservations.payment.store', $record), ['confirm_payment' => 1])->assertSessionHasErrors('payment');
        $this->transition($record, 'ready_for_pickup', 'completed');
        $this->assertNotNull($record->fresh()->qrTicket->used_at);
        $this->assertSame(1, $slot->fresh()->remainingSlots());
        $committedCounts = [DB::table('notifications')->count(), $record->statusHistories()->count()];
        $this->patch(route('admin.reservations.status', $record), ['status' => 'completed', 'expected_status' => 'ready_for_pickup'])
            ->assertSessionHasErrors('status');
        $this->postJson('/admin/qr-verification', ['payload' => $payload])->assertJsonPath('valid', false);
        $this->assertSame($committedCounts, [DB::table('notifications')->count(), $record->statusHistories()->count()]);
        $this->assertSame($snapshots, [$record->fresh()->resident_snapshot, $record->fresh()->document_template_snapshot, $record->fresh()->appointment_date->toDateString()]);
        $this->assertSame($admin->id, $record->fresh()->payment->recorded_by);
        $this->assertSame($admin->id, $document->generated_by);
        $this->assertSame($admin->id, $record->checkinLogs()->sole()->verified_by);
        $this->assertSame(0, $record->statusHistories()->where('changed_by', '!=', $admin->id)->count());
        $this->asUser($resident);
        $finalMetadata = $document->fresh()->getAttributes();
        $this->put(route('resident.profile.update'), [...$identity, 'first_name' => 'Changed', 'address_street' => 'Changed Sample Street'])
            ->assertSessionHasNoErrors();
        $this->get(route('resident.reservations.generated.pdf', $record))->assertOk()->assertContent($reviewedBytes);
        $this->assertSame($finalMetadata, $document->fresh()->getAttributes());
        $this->assertSame($snapshots[0], $record->fresh()->resident_snapshot);
        $this->asUser(User::factory()->residencyApproved()->create(['role' => UserRole::Resident, 'is_active' => true]));
        $this->get(route('resident.reservations.generated.pdf', $record))->assertNotFound();
        $this->get(route('resident.reservations.document-preview.show', $record))->assertNotFound();
    }

    private function failAfterNotification(): void
    {
        $this->app->bind(ReservationNotifications::class, fn () => new class extends ReservationNotifications
        {
            public function record(string $event, Reservation $reservation, ?Model $source = null): void
            {
                parent::record($event, $reservation, $source);
                throw new RuntimeException('Synthetic operational rollback.');
            }
        });
        $this->withoutExceptionHandling();
    }

    public function test_approval_notification_failure_rolls_back_status_ticket_history_and_notifications(): void
    {
        $resident = User::factory()->residencyApproved()->create();
        $service = Service::create(['name' => 'Synthetic rollback', 'fee_amount' => '0.00']);
        $record = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $this->schedule()->id, 'status' => 'under_review']);
        $before = $record->fresh()->getAttributes();
        $this->asUser($this->admin());
        $this->failAfterNotification();
        try {
            $this->patch(route('admin.reservations.status', $record), ['status' => 'approved', 'expected_status' => 'under_review']);
            $this->fail('Synthetic failure must abort the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic operational rollback.', $exception->getMessage());
        }
        $this->assertSame($before, $record->fresh()->getAttributes());
        foreach (['qr_tickets', 'reservation_status_histories', 'notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_payment_notification_failure_rolls_back_payment_and_preserves_eligibility(): void
    {
        $resident = User::factory()->residencyApproved()->create();
        $service = Service::create(['name' => 'Synthetic payment rollback', 'fee_amount' => '42.00']);
        $record = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $this->schedule()->id, 'status' => 'ready_for_pickup']);
        $record->forceFill(['fee_snapshot' => '42.00'])->save();
        $before = $record->fresh()->getAttributes();
        $this->asUser($this->admin());
        $this->failAfterNotification();
        try {
            $this->post(route('admin.reservations.payment.store', $record), ['confirm_payment' => 1]);
            $this->fail('Synthetic failure must abort the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic operational rollback.', $exception->getMessage());
        }
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertDatabaseCount('reservation_payments', 0);
        $this->assertDatabaseCount('notifications', 0);
    }
}
