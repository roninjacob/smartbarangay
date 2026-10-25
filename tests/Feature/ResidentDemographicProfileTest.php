<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DocumentField;
use App\Models\Reservation;
use App\Models\ResidentProfile;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CanonicalDocumentPreview;
use App\Services\GeneratedReservationDocuments;
use App\Services\ReservationDocumentInformation;
use App\Services\ReservationDocumentPreview;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class ResidentDemographicProfileTest extends TestCase
{
    private User $resident;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Storage::fake('local');
        $this->withoutVite();
        $this->resident = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true, 'address' => 'Legacy account address']);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['birthdate' => '2000-02-29', 'civil_status' => 'Single', 'occupation' => 'Teacher',
            'address_street' => 'Sample Street', 'address_zone_purok' => 'Purok 1',
            'emergency_contact_name' => 'Emergency Person', 'emergency_contact_number' => '09123456789'], $changes);
    }

    private function save(array $changes = [])
    {
        return $this->actingAs($this->resident)->put(route('resident.profile.demographics.update'), $this->payload($changes));
    }

    public function test_resident_creates_and_updates_one_profile_without_overwriting_legacy_address(): void
    {
        $this->actingAs($this->resident)->get(route('resident.home'))->assertOk()->assertDontSee('Save resident profile');
        $this->get(route('resident.profile.edit'))->assertOk()->assertSee('Demographic information')->assertSee('Save resident profile');
        $this->assertDatabaseCount('resident_profiles', 0);
        $this->save()->assertRedirect(route('resident.profile.edit'))->assertSessionHasNoErrors();
        $profile = ResidentProfile::sole();
        $this->assertSame($this->resident->id, $profile->user_id);
        $this->assertSame('2000-02-29', $profile->birthdate->toDateString());
        $this->save(['occupation' => 'Farmer'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('resident_profiles', 1);
        $this->assertSame($profile->id, $this->resident->profile->id);
        $values = app(ReservationDocumentInformation::class)->profile($this->resident);
        $this->assertSame('Farmer', $values['resident_occupation']);
        $this->assertSame('Single', $values['resident_civil_status']);
        $this->assertSame('2000-02-29', $values['resident_birthdate']);
        $this->assertSame('Sample Street', $values['resident_address_street']);
        $this->assertSame('Purok 1', $values['resident_address_zone_purok']);
        $this->assertSame('Legacy account address', $values['resident_address']);
        $this->assertSame('Legacy account address', $this->resident->fresh()->address);
        $this->assertArrayNotHasKey('emergency_contact_name', $values);
        $this->assertArrayNotHasKey('emergency_contact_number', $values);
    }

    public function test_parent_user_is_locked_inside_transaction_before_profile_write(): void
    {
        $grammar = new class(DB::connection()) extends SQLiteGrammar
        {
            public bool $lockedUserRead = false;

            public function compileSelect(Builder $query)
            {
                if ($query->from === 'users' && $query->lock === true && $query->getConnection()->transactionLevel() > 0) {
                    $this->lockedUserRead = true;
                }

                return parent::compileSelect($query);
            }
        };
        DB::connection()->setQueryGrammar($grammar);
        $writes = 0;
        ResidentProfile::saving(function () use ($grammar, &$writes) {
            $this->assertTrue($grammar->lockedUserRead);
            $this->assertGreaterThan(0, DB::transactionLevel());
            $writes++;
        });
        try {
            $this->save()->assertSessionHasNoErrors();
            $grammar->lockedUserRead = false;
            $this->save(['occupation' => 'Farmer'])->assertSessionHasNoErrors();
            $this->assertSame(2, $writes);
        } finally {
            ResidentProfile::flushEventListeners();
        }
    }

    public static function invalidInputs(): array
    {
        return [['birthdate', '2099-01-01'], ['birthdate', '2025-02-30'], ['birthdate', ['bad']],
            ['civil_status', str_repeat('x', 81)], ['occupation', str_repeat('x', 121)],
            ['address_street', str_repeat('x', 256)], ['address_zone_purok', str_repeat('x', 121)],
            ['emergency_contact_name', str_repeat('x', 256)], ['emergency_contact_number', 'not-a-phone'], ['occupation', ['bad']]];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_data_is_rejected_and_form_rerenders_safely(string $field, mixed $value): void
    {
        $this->save([$field => $value])->assertRedirect(route('resident.profile.edit'))->assertSessionHasErrors($field);
        $this->assertDatabaseCount('resident_profiles', 0);
        $this->get(route('resident.profile.edit'))->assertOk()->assertSee('Your profile was not saved.');
    }

    public function test_access_requires_active_verified_resident_and_ownership_is_not_client_selectable(): void
    {
        $this->put(route('resident.profile.demographics.update'), $this->payload())->assertRedirect(route('login'));
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->actingAs($admin)->put(route('resident.profile.demographics.update'), $this->payload())->assertForbidden();
        $unverified = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true, 'email_verified_at' => null]);
        $this->actingAs($unverified)->put(route('resident.profile.demographics.update'), $this->payload())->assertRedirect(route('verification.notice'));
        $inactive = User::factory()->create(['role' => UserRole::Resident, 'is_active' => false]);
        $this->actingAs($inactive)->put(route('resident.profile.demographics.update'), $this->payload())->assertRedirect(route('login'));
        $this->save(['user_id' => $admin->id, 'role' => 'admin', 'address' => 'Injected address'])->assertSessionHasErrors('profile');
        $this->assertDatabaseCount('resident_profiles', 0);
        $this->assertSame('Legacy account address', $this->resident->fresh()->address);
        $this->actingAs($admin)->get(route('admin.home'))->assertDontSee('Save resident profile');
        $this->get(route('admin.profile.edit'))->assertDontSee('Save resident profile');
    }

    public function test_empty_optional_fields_clear_to_null_and_remain_valid_document_values(): void
    {
        $this->save()->assertSessionHasNoErrors();
        $empty = array_fill_keys(array_keys($this->payload()), '  ');
        $this->save($empty)->assertSessionHasNoErrors();
        $profile = ResidentProfile::sole();
        foreach (array_keys($this->payload()) as $field) {
            $this->assertNull($profile->{$field});
        }
        $values = app(ReservationDocumentInformation::class)->profile($this->resident);
        $this->assertNull($values['resident_birthdate']);
        $this->assertNull($values['resident_civil_status']);
    }

    public function test_private_values_are_escaped_and_not_projected_on_public_home(): void
    {
        $this->save(['occupation' => '<script>alert(1)</script>'])->assertSessionHasNoErrors();
        $this->get(route('resident.profile.edit'))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/')->assertDontSee('Emergency Person')->assertDontSee('2000-02-29')->assertDontSee('Sample Street');
    }

    public function test_demographic_changes_use_existing_snapshot_preview_and_exact_final_pdf_pipeline(): void
    {
        $this->save()->assertSessionHasNoErrors();
        $service = Service::create(['name' => 'Demographic Certificate', 'fee_amount' => '0.00']);
        $bytes = CertificatePdf::bytes();
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('a', 40).'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $master = $service->documentTemplate()->create(['original_filename' => 'Official.pdf', 'stored_path' => $path, 'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'uploaded_at' => now()]);
        $template = $service->dynamicDocumentTemplate()->make();
        $template->forceFill(['service_document_template_id' => $master->id, 'source_revision' => $master->revision(), 'source_content_hash' => hash('sha256', $bytes), 'source_filename' => 'Official.pdf', 'version' => 1, 'is_active' => true])->save();
        $names = ['resident_civil_status', 'resident_occupation', 'resident_birthdate', 'resident_address_street', 'resident_address_zone_purok'];
        foreach ($names as $index => $name) {
            $field = $template->fields()->create(['field_name' => $name, 'label' => $name, 'source' => 'system', 'field_type' => DocumentField::SYSTEM_FIELDS[$name], 'is_required' => false]);
            $field->positions()->create(['page_number' => 1, 'x_position' => 40, 'y_position' => 70 + $index * 40, 'width' => 400, 'height' => 28, 'font_size' => 12, 'alignment' => 'left', 'text_behavior' => 'wrap']);
        }
        $schedule = Schedule::create(['date' => '2027-01-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5]);
        $this->post(route('resident.reservations.service'), ['service_id' => $service->id])->assertRedirect();
        $this->post(route('resident.reservations.requirements'), ['document_signature' => app(ReservationDocumentInformation::class)->signature($template->load('fields'))])->assertRedirect();
        $this->post(route('resident.reservations.schedule.select'), ['schedule_id' => $schedule->id])->assertRedirect();
        $this->post(route('resident.reservations.store'), ['confirmation_token' => session('reservation_wizard.confirmation_token'), 'resident_civil_status' => 'Spoofed'])->assertRedirect(route('resident.home'));
        $reservation = Reservation::sole();
        $snapshot = $reservation->resident_snapshot;
        $this->save(['civil_status' => 'Married', 'occupation' => 'Farmer'])->assertSessionHasNoErrors();
        $this->assertSame($snapshot, $reservation->fresh()->resident_snapshot);
        $data = app(ReservationDocumentPreview::class)->data($reservation->fresh());
        $this->assertSame(['Single', 'Teacher', '2000-02-29', 'Sample Street', 'Purok 1'], array_column($data['placements'], 'text'));
        $this->assertSame('Single', $reservation->resident_snapshot['resident_civil_status']);
        $reservation->update(['status' => 'approved']);
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $artifact = app(CanonicalDocumentPreview::class)->create($reservation->fresh(), $admin);
        $reviewed = app(CanonicalDocumentPreview::class)->reviewed($reservation->fresh(), $admin, $artifact['token']);
        app(GeneratedReservationDocuments::class)->generate($reservation->fresh(), $admin, $artifact['token']);
        $this->assertSame($reviewed, app(GeneratedReservationDocuments::class)->bytes($reservation->fresh()));
        $this->assertStringStartsWith('%PDF-', $reviewed);
    }

    public function test_profile_form_is_csrf_protected(): void
    {
        $this->app->instance('env', 'local');
        try {
            $this->save()->assertStatus(419);
            $this->assertDatabaseCount('resident_profiles', 0);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_registration_stays_short_and_describes_the_verified_profile_step(): void
    {
        $this->get(route('register'))->assertOk()->assertSee('verify your email, then complete your profile')
            ->assertDontSee('name="birthdate"', false)->assertDontSee('name="civil_status"', false)
            ->assertDontSee('name="emergency_contact_name"', false);
    }

    public function test_required_profile_fields_block_only_the_selected_document_and_resume_after_save(): void
    {
        [$service, $template, $field] = $this->profileTemplate(true);
        $this->actingAs($this->resident)->post(route('resident.reservations.service'), ['service_id' => $service->id])->assertRedirect();
        $page = $this->get(route('resident.reservations.requirements'))->assertOk()
            ->assertSee('Complete the profile information required for this document.')
            ->assertSee('Missing: Civil status.')->assertSee('type="submit" disabled', false);
        $this->assertSame(['Civil status'], $page->viewData('missingProfileFields'));
        $signature = app(ReservationDocumentInformation::class)->signature($template->load('fields'));
        $this->from(route('resident.reservations.requirements'))->post(route('resident.reservations.requirements.review'), ['document_signature' => $signature])
            ->assertSessionHasErrors('document_answers.'.$field->id);
        $this->assertDatabaseCount('reservations', 0);
        $this->get(route('resident.profile.edit'))->assertOk()->assertSee('Return to document form');
        $this->save()->assertRedirect(route('resident.profile.edit'));
        $page = $this->get(route('resident.reservations.requirements'))->assertOk()->assertSee('Single')
            ->assertDontSee('Complete the profile information required for this document.');
        $this->assertSame([], $page->viewData('missingProfileFields'));
        $this->post(route('resident.reservations.requirements.review'), ['document_signature' => $signature])
            ->assertRedirect(route('resident.reservations.schedule'));
        $this->get(route('resident.home'))->assertOk()->assertDontSee('Save resident profile');
    }

    public function test_missing_optional_profile_fields_do_not_block_a_request(): void
    {
        [$service, $template] = $this->profileTemplate(false);
        $this->actingAs($this->resident)->post(route('resident.reservations.service'), ['service_id' => $service->id]);
        $page = $this->get(route('resident.reservations.requirements'))->assertOk();
        $this->assertSame([], $page->viewData('missingProfileFields'));
        $this->post(route('resident.reservations.requirements.review'), [
            'document_signature' => app(ReservationDocumentInformation::class)->signature($template->load('fields')),
        ])->assertRedirect(route('resident.reservations.schedule'));
    }

    public function test_foreign_draft_is_not_offered_as_a_profile_return_destination(): void
    {
        $this->actingAs($this->resident)->withSession(['reservation_wizard' => ['resident_id' => $this->resident->id + 1, 'service_id' => 999]])
            ->get(route('resident.profile.edit'))->assertOk()->assertDontSee('Return to document form')
            ->assertSee('Start a document request');
    }

    private function profileTemplate(bool $required): array
    {
        $service = Service::create(['name' => 'Profile-guided Certificate', 'fee_amount' => '0.00']);
        $bytes = CertificatePdf::bytes();
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('b', 40).'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $master = $service->documentTemplate()->create(['original_filename' => 'Official.pdf', 'stored_path' => $path,
            'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'uploaded_at' => now()]);
        $template = $service->dynamicDocumentTemplate()->make();
        $template->forceFill(['service_document_template_id' => $master->id, 'source_revision' => $master->revision(),
            'source_content_hash' => hash('sha256', $bytes), 'source_filename' => 'Official.pdf', 'version' => 1, 'is_active' => true])->save();
        $field = $template->fields()->create(['field_name' => 'resident_civil_status', 'label' => 'Civil status',
            'source' => 'system', 'field_type' => 'text', 'is_required' => $required]);
        $field->positions()->create(['page_number' => 1, 'x_position' => 40, 'y_position' => 70, 'width' => 250,
            'height' => 30, 'font_size' => 12, 'alignment' => 'left', 'text_behavior' => 'wrap']);

        return [$service, $template, $field];
    }

    public function test_cleanup_retains_a_demographic_profile_if_verification_is_later_removed(): void
    {
        $this->save()->assertSessionHasNoErrors();
        $this->resident->forceFill(['email_verified_at' => null, 'created_at' => now()->subDays(9)])->save();
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--dry-run' => true])
            ->expectsOutputToContain('eligible: 0')->assertSuccessful();
        $this->artisan('smartbarangay:cleanup-unverified-users')->assertSuccessful();
        $this->assertDatabaseHas('users', ['id' => $this->resident->id]);
        $this->assertDatabaseCount('resident_profiles', 1);
    }
}
