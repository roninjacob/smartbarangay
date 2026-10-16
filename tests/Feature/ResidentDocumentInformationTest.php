<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DocumentTemplate;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\ReservationDocumentInformation;
use App\Services\ReservationFileUploads;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class ResidentDocumentInformationTest extends TestCase
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

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function fixture(): array
    {
        $resident = User::factory()->create(['role' => UserRole::Resident, 'name' => 'Original Resident', 'address' => 'Calayo original address', 'contact_number' => '09123456789', 'is_active' => true]);
        $service = Service::create(['fee_amount' => '0.00', 'name' => 'Generic Configurable Document']);
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('a', 40).'.pdf';
        $bytes = CertificatePdf::bytes();
        Storage::disk('local')->put($path, $bytes);
        $pdf = $service->documentTemplate()->create(['original_filename' => 'Official.pdf', 'stored_path' => $path, 'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'uploaded_at' => now()]);
        $template = $service->dynamicDocumentTemplate()->make();
        $template->forceFill(['service_document_template_id' => $pdf->id, 'source_revision' => $pdf->revision(), 'source_content_hash' => hash('sha256', $bytes), 'source_filename' => 'Official.pdf', 'version' => 1, 'is_active' => true])->save();
        foreach (['resident_name', 'resident_address', 'resident_contact', 'barangay_name', 'date_issued'] as $name) {
            $template->fields()->create(['source' => 'system', 'field_name' => $name, 'label' => ucwords(str_replace('_', ' ', $name)), 'field_type' => $name === 'date_issued' ? 'date' : 'text', 'is_required' => true]);
        }
        foreach (['purpose' => ['Purpose', 'text', ['max_length' => 40], null], 'years' => ['Years of Residency', 'number', ['min' => 0, 'max' => 50], null], 'birth_date' => ['Birth Date', 'date', null, null], 'civil_status' => ['Civil Status', 'dropdown', null, ['Single', 'Married']]] as $name => [$label,$type,$rules,$options]) {
            $template->fields()->create(['source' => 'custom', 'field_name' => $name, 'label' => $label, 'field_type' => $type, 'is_required' => true, 'validation_rules' => $rules, 'options' => $options]);
        }
        $schedule = Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 10, 'is_active' => true]);
        $this->actingAs($resident);

        return [$resident, $service, $template, $schedule];
    }

    private function answers(DocumentTemplate $template, array $overrides = []): array
    {
        $values = ['purpose' => 'Employment', 'years' => '5', 'birth_date' => '2000-02-29', 'civil_status' => 'Single', ...$overrides];

        return $template->fields()->where('source', 'custom')->get()->mapWithKeys(fn ($field) => [$field->id => $values[$field->field_name] ?? null])->all();
    }

    private function start(Service $service): void
    {
        $this->post('/resident/reservations/service', ['service_id' => $service->id])->assertRedirect(route('resident.reservations.requirements'));
    }

    private function information(Service $service, DocumentTemplate $template, array $overrides = [])
    {
        return $this->post('/resident/reservations/requirements', ['document_signature' => app(ReservationDocumentInformation::class)->signature($template->fresh('fields')), 'document_answers' => $this->answers($template, $overrides)]);
    }

    private function draft(Service $service, DocumentTemplate $template, Schedule $schedule): string
    {
        $this->start($service);
        $this->information($service, $template)->assertRedirect(route('resident.reservations.schedule'));
        $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id])->assertRedirect(route('resident.reservations.confirm'));
        $this->get('/resident/reservations/confirm')->assertOk()->assertSee('Employment');

        return session('reservation_wizard.confirmation_token');
    }

    public function test_configured_display_order_is_preserved_in_the_resident_form(): void
    {
        [, $service, $template] = $this->fixture();
        $template->fields()->where('field_name', 'civil_status')->update(['sort_order' => 100]);
        $template->fields()->where('field_name', 'purpose')->update(['sort_order' => 200]);
        $this->start($service);
        $html = $this->get('/resident/reservations/requirements')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'Purpose'), strpos($html, 'Civil Status'));
    }

    public function test_active_configuration_renders_all_types_and_read_only_system_fields(): void
    {
        [$resident,$service,$template] = $this->fixture();
        $this->start($service);
        $this->get('/resident/reservations/requirements')->assertOk()->assertSee('Original Resident')->assertSee('Calayo original address')->assertSee('Purpose')->assertSee('Years of Residency')->assertSee('Civil Status')->assertSee('Birth Date')->assertSee('type="number"', false)->assertSee('type="date"', false)->assertSee('maxlength="40"', false)->assertDontSee('name="document_answers[1]"', false)->assertDontSee($service->documentTemplate->stored_path, false);
        $this->assertDatabaseCount('reservation_document_answers', 0);
    }

    public static function inactiveOrAbsent(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('inactiveOrAbsent')]
    public function test_inactive_or_absent_configuration_preserves_original_workflow(bool $absent): void
    {
        [$resident,$service,$template,$schedule] = $this->fixture();
        $absent ? $template->delete() : $template->update(['is_active' => false]);
        $this->start($service);
        $this->get('/resident/reservations/requirements')->assertOk()->assertDontSee('Years of Residency');
        $this->post('/resident/reservations/requirements')->assertRedirect(route('resident.reservations.schedule'));
        $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id]);
        $token = session('reservation_wizard.confirmation_token');
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.home'));
        $this->assertDatabaseCount('reservation_document_answers', 0);
        $this->assertSame('Original Resident', Reservation::sole()->resident_snapshot['resident_name']);
        $this->assertNull(Reservation::sole()->document_template_snapshot);
    }

    public static function invalidAnswers(): array
    {
        return [[['purpose' => '']], [['purpose' => str_repeat('a', 41)]], [['purpose' => ['script']]], [['years' => -1]], [['years' => 51]], [['years' => 'not numeric']], [['years' => '1e999']], [['birth_date' => '2026-02-30']], [['birth_date' => 'invalid']], [['civil_status' => 'Unconfigured option']], [['civil_status' => ['Single']]]];
    }

    #[DataProvider('invalidAnswers')]
    public function test_metadata_validation_rejects_invalid_answers(array $overrides): void
    {
        [$resident,$service,$template] = $this->fixture();
        $this->start($service);
        $fieldId = $template->fields()->where('field_name', array_key_first($overrides))->sole()->id;
        $this->information($service, $template, $overrides)->assertSessionHasErrors('document_answers.'.$fieldId);
        $this->assertFalse((bool) session('reservation_wizard.requirements_reviewed'));
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_document_answers', 0);
    }

    public function test_foreign_fields_and_system_answer_spoofing_are_rejected(): void
    {
        [$resident,$service,$template] = $this->fixture();
        $this->start($service);
        $answers = $this->answers($template);
        $signature = app(ReservationDocumentInformation::class)->signature($template->fresh('fields'));
        foreach ([9999, $template->fields()->where('source', 'system')->first()->id] as $id) {
            $this->post('/resident/reservations/requirements', ['document_signature' => $signature, 'document_answers' => array_replace($answers, [$id => 'Spoofed data'])])->assertSessionHasErrors('document_answers');
        }
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_answers_and_profile_snapshot_save_atomically_and_ignore_frontend_ownership_or_snapshot_values(): void
    {
        [$resident,$service,$template,$schedule] = $this->fixture();
        $bytes = Storage::disk('local')->get($service->documentTemplate->stored_path);
        $fieldData = $template->fields()->get()->toJson();
        $token = $this->draft($service, $template, $schedule);
        $this->post('/resident/reservations', ['confirmation_token' => $token, 'user_id' => 999, 'service_id' => 999, 'resident_snapshot' => ['resident_name' => 'Spoofed'], 'document_answers' => [6 => 'Changed after review']])->assertRedirect(route('resident.home'));
        $reservation = Reservation::sole();
        $this->assertSame($resident->id, $reservation->user_id);
        $this->assertSame($service->id, $reservation->service_id);
        $this->assertCount(9, $reservation->documentAnswers);
        $this->assertSame('Original Resident', $reservation->resident_snapshot['resident_name']);
        $this->assertSame('Calayo original address', $reservation->resident_snapshot['resident_address']);
        $this->assertSame(1, $reservation->document_template_snapshot['version']);
        $this->assertSame('Employment', $reservation->documentAnswers->firstWhere('document_field_id', $template->fields()->where('field_name', 'purpose')->sole()->id)->answer);
        $this->assertSame($fieldData, $template->fields()->get()->toJson());
        $this->assertSame($bytes, Storage::disk('local')->get($service->documentTemplate->stored_path));
        $this->assertDatabaseCount('qr_tickets', 0);
        $this->assertDatabaseCount('reservation_documents', 0);
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.create'));
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_document_answers', 9);
    }

    public function test_historical_answers_survive_profile_field_edits_and_field_deletion_with_owner_only_views(): void
    {
        [$resident,$service,$template,$schedule] = $this->fixture();
        $token = $this->draft($service, $template, $schedule);
        $this->post('/resident/reservations', ['confirmation_token' => $token]);
        $reservation = Reservation::sole();
        $snapshot = $reservation->resident_snapshot;
        $answers = $reservation->documentAnswers()->get()->toJson();
        $resident->update(['name' => 'Later Name', 'address' => 'Later Address', 'contact_number' => '09999999999']);
        $purpose = $template->fields()->where('field_name', 'purpose')->sole();
        $purpose->update(['label' => 'Changed label', 'field_type' => 'number']);
        $this->assertSame($snapshot, $reservation->fresh()->resident_snapshot);
        $this->assertSame($answers, $reservation->documentAnswers()->get()->toJson());
        $purpose->delete();
        $this->actingAs($resident)->get('/resident/reservations/'.$reservation->id)->assertOk()->assertSee('Original Resident')->assertSee('Calayo original address')->assertSee('Purpose')->assertSee('Employment')->assertDontSee('Changed label');
        $this->actingAs(User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]))->get('/resident/reservations/'.$reservation->id)->assertNotFound()->assertDontSee('Employment');
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->actingAs($admin)->get('/admin/reservations/'.$reservation->id)->assertOk()->assertSee('Original Resident')->assertSee('Purpose')->assertSee('Employment');
        $this->assertDatabaseCount('reservation_document_answers', 9);
        $this->assertTrue($reservation->documentAnswers()->whereNull('document_field_id')->exists());
    }

    public function test_changed_fields_or_new_active_template_require_revisiting_information_before_submission(): void
    {
        [$resident,$service,$template,$schedule] = $this->fixture();
        $token = $this->draft($service, $template, $schedule);
        $template->fields()->where('field_name', 'purpose')->update(['is_required' => false]);
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.requirements'))->assertSessionHasErrors('reservation')->assertSessionMissing('reservation_wizard.confirmation_token');
        $this->assertDatabaseCount('reservations', 0);
        $template->update(['is_active' => false]);
        $this->start($service);
        $this->post('/resident/reservations/requirements');
        $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id]);
        $template->update(['is_active' => true]);
        $this->get('/resident/reservations/confirm')->assertRedirect(route('resident.reservations.requirements'));
        $this->assertDatabaseCount('reservation_document_answers', 0);
    }

    public function test_stale_information_form_and_profile_changes_are_rejected(): void
    {
        [$resident,$service,$template,$schedule] = $this->fixture();
        $this->start($service);
        $signature = app(ReservationDocumentInformation::class)->signature($template->fresh('fields'));
        $template->increment('version');
        $this->post('/resident/reservations/requirements', ['document_signature' => $signature, 'document_answers' => $this->answers($template)])->assertRedirect(route('resident.reservations.requirements'));
        $token = $this->draft($service, $template, $schedule);
        $resident->update(['address' => 'New Address']);
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.requirements'));
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_skipping_document_step_missing_profile_and_tampered_session_answers_cannot_submit(): void
    {
        [$resident,$service,$template,$schedule] = $this->fixture();
        $this->start($service);
        $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id])->assertRedirect(route('resident.reservations.requirements'));
        $resident->update(['address' => null]);
        $this->information($service, $template)->assertSessionHasErrors('document_answers.2');
        $resident->update(['address' => 'Calayo original address']);
        $token = $this->draft($service, $template, $schedule);
        $draft = session('reservation_wizard');
        $draft['document_answers'][999] = 'foreign';
        $this->withSession(['reservation_wizard' => $draft])->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.requirements'));
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_capacity_duplicates_and_attachments_remain_enforced_without_partial_answers(): void
    {
        [$resident,$service,$template,$schedule] = $this->fixture();
        $token = $this->draft($service, $template, $schedule);
        $schedule->update(['capacity' => 1]);
        $other = User::factory()->create();
        $existing = $other->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => 'pending']);
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.schedule'));
        $this->assertDatabaseCount('reservation_document_answers', 0);
        $existing->update(['status' => 'completed']);
        $token = $this->draft($service, $template, $schedule);
        $service->serviceRequirements()->create(['name' => 'Valid ID', 'is_required' => true]);
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertSessionHasErrors('attachments.1');
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_document_answers', 0);
        $service->serviceRequirements()->update(['is_required' => false]);
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.home'));
        $this->assertDatabaseCount('reservation_document_answers', 9);
        $schedule->update(['capacity' => 2]);
        $token = $this->draft($service, $template, $schedule);
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertSessionHasErrors('reservation');
        $this->assertDatabaseCount('reservation_document_answers', 9);
    }

    public function test_invalid_reentry_clears_old_confirmation_and_optional_inputs_and_zero_are_supported(): void
    {
        [$resident, $service, $template, $schedule] = $this->fixture();
        $token = $this->draft($service, $template, $schedule);
        $this->information($service, $template, ['purpose' => ''])->assertSessionHasErrors('document_answers.6')->assertSessionMissing('reservation_wizard.confirmation_token')->assertSessionMissing('reservation_wizard.requirements_reviewed');
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.requirements'));
        $template->fields()->where('field_name', 'purpose')->update(['is_required' => false]);
        $this->information($service, $template, ['purpose' => '', 'years' => '0'])->assertRedirect(route('resident.reservations.schedule'));
        $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id]);
        $this->post('/resident/reservations', ['confirmation_token' => session('reservation_wizard.confirmation_token')])->assertRedirect(route('resident.home'));
        $answers = Reservation::sole()->documentAnswers;
        $this->assertNull($answers->firstWhere('document_field_id', 6)->answer);
        $this->assertSame('0', $answers->firstWhere('document_field_id', 7)->answer);
    }

    public function test_failure_after_answers_are_written_rolls_back_the_entire_reservation(): void
    {
        [$resident, $service, $template, $schedule] = $this->fixture();
        $token = $this->draft($service, $template, $schedule);
        $this->app->bind(ReservationFileUploads::class, fn () => new class extends ReservationFileUploads
        {
            public function store(Reservation $reservation, array $files, array &$storedPaths): void
            {
                throw new \RuntimeException('Simulated attachment storage failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/resident/reservations', ['confirmation_token' => $token]);
            $this->fail('Expected the simulated storage exception.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated attachment storage failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_document_answers', 0);
        $this->assertDatabaseCount('reservation_attachments', 0);
        $this->assertSame($token, session('reservation_wizard.confirmation_token'));
    }

    public function test_submitted_text_and_historical_labels_are_escaped_when_displayed(): void
    {
        [$resident, $service, $template, $schedule] = $this->fixture();
        $this->start($service);
        $value = '<script>alert(1)</script>';
        $this->information($service, $template, ['purpose' => $value])->assertRedirect(route('resident.reservations.schedule'));
        $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id]);
        $this->get('/resident/reservations/confirm')->assertOk()->assertDontSee($value, false)->assertSee(e($value), false);
        $this->post('/resident/reservations', ['confirmation_token' => session('reservation_wizard.confirmation_token')]);
        $reservation = Reservation::sole();
        $this->get('/resident/reservations/'.$reservation->id)->assertOk()->assertDontSee($value, false)->assertSee(e($value), false);
        $this->assertSame($value, $reservation->documentAnswers()->where('document_field_id', 6)->sole()->answer);
    }

    public function test_new_document_inputs_preserve_csrf(): void
    {
        [$resident,$service,$template] = $this->fixture();
        $this->start($service);
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->information($service, $template)->assertStatus(419);
        $this->assertDatabaseCount('reservation_document_answers', 0);
    }
}
