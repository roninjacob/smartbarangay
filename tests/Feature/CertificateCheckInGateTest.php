<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\ReservationController;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CanonicalDocumentPreview;
use App\Services\DocumentTemplateConfiguration;
use App\Services\GeneratedReservationDocuments;
use App\Services\QrTicketIssuer;
use App\Services\ReservationDocumentInformation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

/**
 * Finalize and print need Ready for Pickup plus a recorded check-in; the finalized PDF is admin-only;
 * completion needs a recorded check-in next to the payment rule.
 */
class CertificateCheckInGateTest extends TestCase
{
    private User $admin;

    private User $owner;

    private User $stranger;

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

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function fixture(string $status = 'approved', string $fee = '0.00'): Reservation
    {
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->owner = User::factory()->residencyApproved()->create(['role' => UserRole::Resident, 'is_active' => true, 'name' => 'Synthetic Owner']);
        $this->stranger = User::factory()->residencyApproved()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $service = Service::create(['fee_amount' => $fee, 'name' => 'Synthetic Certificate']);
        $bytes = CertificatePdf::twoPages();
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('a', 40).'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $master = $service->documentTemplate()->create(['original_filename' => 'A.pdf', 'stored_path' => $path,
            'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'uploaded_at' => now()]);
        app(DocumentTemplateConfiguration::class)->create($service, ['pdf_id' => $master->id,
            'pdf_revision' => $master->revision(), 'is_active' => true], $this->admin->id);
        $template = $service->dynamicDocumentTemplate()->first();
        $field = $template->fields()->create(['field_name' => 'resident_name', 'source' => 'system',
            'label' => 'Resident Name', 'field_type' => 'text', 'is_required' => true]);
        $field->positions()->create(['page_number' => 1, 'x_position' => 40, 'y_position' => 90,
            'width' => 230, 'height' => 30, 'font_size' => 12, 'alignment' => 'left', 'text_behavior' => 'wrap']);
        $hour = 8 + Schedule::count(); // one slot per fixture
        $schedule = Schedule::create(['date' => '2026-12-01', 'start_time' => sprintf('%02d:00', $hour), 'end_time' => sprintf('%02d:00', $hour + 1), 'capacity' => 10]);
        $reservation = $this->owner->reservations()->make(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
        $reservation->forceFill(['fee_snapshot' => $fee])->save();
        app(ReservationDocumentInformation::class)->store($reservation, $template->load('fields'), [], app(ReservationDocumentInformation::class)->profile($this->owner));
        app(QrTicketIssuer::class)->issue($reservation);
        $this->actingAs($this->admin);

        return $reservation->fresh();
    }

    /** Verified scan through the real endpoint: evidence in the admin session, nothing recorded. */
    private function scan(Reservation $reservation): void
    {
        $this->postJson('/admin/qr-verification', ['payload' => $reservation->qrTicket->encodedPayload()])
            ->assertOk()->assertJsonPath('valid', true);
    }

    /** Real check-in: verified scan, then the explicit admin action. */
    private function checkIn(Reservation $reservation): void
    {
        $this->scan($reservation);
        $this->post(route('admin.reservations.check-in.store', $reservation), ['confirm_checkin' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('checkin_logs', ['reservation_id' => $reservation->id, 'verification_result' => 'checked_in']);
    }

    private function finalize(Reservation $reservation)
    {
        $preview = app(CanonicalDocumentPreview::class);
        $artifact = $preview->create($reservation->fresh(), $this->admin);
        $preview->reviewed($reservation->fresh(), $this->admin, $artifact['token']);

        return $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $artifact['token']]);
    }

    /** A historical certificate finalized under the old rule (at Approved). */
    private function historicalDocument(Reservation $reservation): string
    {
        $bytes = CertificatePdf::bytes('Historical certificate '.$reservation->id);
        $path = 'reservation-documents/'.$reservation->id.'/'.str_repeat('h', 40).'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $master = $reservation->service->documentTemplate;
        $reservation->document()->create(['service_document_template_id' => $master->id, 'template_revision' => $master->revision(),
            'template_version' => 1, 'template_filename' => 'A.pdf', 'original_filename' => 'Personalized.pdf',
            'stored_path' => $path, 'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'content_hash' => hash('sha256', $bytes),
            'generated_by' => $this->admin->id, 'generated_at' => now()]);

        return $bytes;
    }

    public function test_rule1_finalize_needs_ready_for_pickup_and_a_recorded_check_in(): void
    {
        $reservation = $this->fixture();
        $show = route('admin.reservations.show', $reservation);
        $page = route('admin.reservations.document-preview.show', $reservation);

        // Approved: no finalize button anywhere, server refuses.
        $this->get($show)->assertOk()->assertSee('Finalize: '.GeneratedReservationDocuments::READY_REQUIRED)
            ->assertDontSee('Preview &amp; Finalize Certificate', false);
        $this->get($page)->assertOk()->assertSee('Finalize: '.GeneratedReservationDocuments::READY_REQUIRED)->assertDontSee('data-finalize-button', false);
        $this->finalize($reservation)->assertForbidden();

        // Ready for Pickup without check-in.
        $reservation->update(['status' => ReservationStatus::ReadyForPickup]);
        $this->get($show)->assertSee('Finalize: '.GeneratedReservationDocuments::CHECKIN_REQUIRED)->assertDontSee('Preview &amp; Finalize Certificate', false);
        $this->get($page)->assertSee('Finalize: '.GeneratedReservationDocuments::CHECKIN_REQUIRED)->assertDontSee('data-finalize-button', false);
        $this->finalize($reservation)->assertForbidden();
        $this->assertDatabaseCount('reservation_documents', 0);

        // Recorded check-in unlocks it.
        $this->checkIn($reservation);
        $this->get($show)->assertSee('Preview &amp; Finalize Certificate', false)->assertDontSee('Finalize: ');
        $this->get($page)->assertSee('data-finalize-button', false);
        $this->finalize($reservation)->assertRedirect($show)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('reservation_documents', 1);
        $this->assertSame(ReservationStatus::ReadyForPickup, $reservation->fresh()->status);
    }

    public function test_scan_verification_alone_unlocks_nothing(): void
    {
        $reservation = $this->fixture('ready_for_pickup');
        $this->historicalDocument($reservation);
        $this->scan($reservation);
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->get(route('admin.reservations.document.print', $reservation))->assertForbidden();
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Print: '.GeneratedReservationDocuments::CHECKIN_REQUIRED)
            ->assertDontSee('href="'.route('admin.reservations.document.print', $reservation).'"', false)
            ->assertSee(ReservationController::COMPLETION_CHECKIN_REQUIRED)->assertDontSee('Mark Completed');
        $this->patch(route('admin.reservations.status', $reservation), ['expected_status' => 'ready_for_pickup', 'status' => 'completed'])
            ->assertSessionHasErrors(['status' => ReservationController::COMPLETION_CHECKIN_REQUIRED]);
        $other = $this->fixture('ready_for_pickup');
        $this->scan($other);
        $this->finalize($other)->assertForbidden();
        $this->assertSame(ReservationStatus::ReadyForPickup, $reservation->fresh()->status);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public function test_rule2_and_rule6_print_needs_check_in_and_a_finalized_document(): void
    {
        $reservation = $this->fixture();
        $bytes = $this->historicalDocument($reservation);
        $entry = route('admin.reservations.document.print', $reservation);

        // Historical document finalized at Approved: viewable by admin, not printable.
        $this->get($entry)->assertForbidden();
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Preview Generated Document')
            ->assertSee('Print: '.GeneratedReservationDocuments::READY_REQUIRED)->assertDontSee('href="'.$entry.'"', false);
        $this->get(route('admin.services.certificates.index', $reservation->service))->assertOk()->assertDontSee('href="'.$entry.'"', false)
            ->assertSee('Print: '.GeneratedReservationDocuments::READY_REQUIRED);

        $reservation->update(['status' => ReservationStatus::ReadyForPickup]);
        $this->get($entry)->assertForbidden();
        $this->checkIn($reservation);
        $url = $this->get($entry)->assertRedirect()->headers->get('Location');
        $this->get($url)->assertOk()->assertSee('Print Certificate');
        $this->assertSame($bytes, $this->get($url.'/pdf')->assertOk()->getContent());
        $this->get(route('admin.reservations.show', $reservation))->assertSee('href="'.$entry.'"', false)->assertDontSee('Print: ');

        // Completed: an issued print token stops working, and the entry is refused.
        $reservation->update(['status' => ReservationStatus::Completed]);
        $this->get($url)->assertNotFound();
        $this->get($url.'/pdf')->assertNotFound();
        $this->get($entry)->assertForbidden();

        // No finalized document: still no print even when checked in.
        $bare = $this->fixture('ready_for_pickup');
        $this->checkIn($bare);
        $this->get(route('admin.reservations.document.print', $bare))->assertRedirect(route('admin.reservations.show', $bare))
            ->assertSessionHasErrors('document');
    }

    public static function statuses(): array
    {
        return array_map(fn (ReservationStatus $status) => [$status], ReservationStatus::cases());
    }

    #[DataProvider('statuses')]
    public function test_rule3_and_rule4_finalized_pdf_is_admin_only_in_every_status(ReservationStatus $status): void
    {
        $reservation = $this->fixture();
        $bytes = $this->historicalDocument($reservation);
        $reservation->update(['status' => $status]);
        $this->get(route('admin.reservations.generated.show', $reservation))->assertOk();
        $this->assertSame($bytes, $this->get(route('admin.reservations.generated.pdf', $reservation))->assertOk()->getContent());
        $this->get(route('admin.reservations.generated.download', $reservation))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="Certificate.pdf"');
        foreach ([$this->owner, $this->stranger] as $resident) {
            $this->actingAs($resident);
            foreach (['show', 'pdf', 'download'] as $action) {
                $this->get(route('resident.reservations.generated.'.$action, $reservation))->assertNotFound()->assertDontSee($bytes, false);
                $this->get(route('admin.reservations.generated.'.$action, $reservation))->assertForbidden();
            }
        }
        $this->actingAs($this->owner)->get(route('resident.reservations.show', $reservation))->assertOk()
            ->assertDontSee('generated-document', false)->assertDontSee('Preview Generated Document');
    }

    public function test_resident_preview_is_unchanged_before_finalization_and_points_to_the_office_after(): void
    {
        $reservation = $this->fixture();
        $this->actingAs($this->owner);
        $page = $this->get(route('resident.reservations.document-preview.show', $reservation))->assertOk();
        $artifact = $page->viewData('artifact');
        $this->assertNotNull($artifact);
        $this->get(route('resident.reservations.document-preview.pdf', [$reservation, 'preview' => $artifact['token']]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->stranger)->get(route('resident.reservations.document-preview.show', $reservation))->assertNotFound();

        $this->actingAs($this->admin);
        $this->historicalDocument($reservation);
        $this->actingAs($this->owner);
        $this->get(route('resident.reservations.document-preview.show', $reservation))->assertOk()
            ->assertSee('Your certificate is ready. Collect it at the barangay office.')
            ->assertViewHas('artifact', null)->assertDontSee('generated-document', false);
        $this->get(route('resident.reservations.document-preview.pdf', [$reservation, 'preview' => $artifact['token']]))->assertStatus(410);
        // Admin keeps the existing redirect to the finalized document.
        $this->actingAs($this->admin)->get(route('admin.reservations.document-preview.show', $reservation))
            ->assertRedirect(route('admin.reservations.generated.show', $reservation));
    }

    public function test_rule5_completion_needs_check_in_and_keeps_the_payment_rule(): void
    {
        $reservation = $this->fixture('ready_for_pickup', '50.00');
        $complete = fn () => $this->patch(route('admin.reservations.status', $reservation), ['expected_status' => 'ready_for_pickup', 'status' => 'completed']);
        $this->post(route('admin.reservations.payment.store', $reservation), ['confirm_payment' => 1])->assertSessionHasNoErrors();

        // Paid but not checked in: the separate check-in reason.
        $this->get(route('admin.reservations.show', $reservation))->assertSee(ReservationController::COMPLETION_CHECKIN_REQUIRED)
            ->assertSee('href="#checkin-heading"', false)->assertDontSee('Mark Completed');
        $complete()->assertSessionHasErrors(['status' => ReservationController::COMPLETION_CHECKIN_REQUIRED]);
        $this->assertSame(ReservationStatus::ReadyForPickup, $reservation->fresh()->status);
        $this->assertFalse($reservation->qrTicket->fresh()->isUsed());

        // Checked in but unpaid: the existing payment message, not the check-in one.
        $unpaid = $this->fixture('ready_for_pickup', '50.00');
        $this->checkIn($unpaid);
        $response = $this->patch(route('admin.reservations.status', $unpaid), ['expected_status' => 'ready_for_pickup', 'status' => 'completed'])
            ->assertSessionHasErrors('status');
        $this->assertNotSame(ReservationController::COMPLETION_CHECKIN_REQUIRED, session('errors')->first('status'));
        $response->assertRedirect();

        $this->actingAs($this->admin);
        $this->checkIn($reservation);
        $complete()->assertSessionHasNoErrors();
        $this->assertSame(ReservationStatus::Completed, $reservation->fresh()->status);
        $this->assertTrue($reservation->qrTicket->fresh()->isUsed());
    }
}
