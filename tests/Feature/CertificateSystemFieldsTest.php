<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DocumentField;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CanonicalDocumentPreview;
use App\Services\GeneratedReservationDocuments;
use App\Services\ReservationDocumentInformation;
use App\Services\ReservationDocumentPreview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class CertificateSystemFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'cache.default' => 'array', 'session.driver' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Storage::fake('local');
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-11-12 09:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function resident(bool $structured = true): User
    {
        $user = User::factory()->residencyApproved()->create(['role' => UserRole::Resident, 'is_active' => true,
            'name' => 'Legacy Name, Kept Whole', 'address' => 'Legacy complete address', 'contact_number' => '09171234567']);
        if ($structured) {
            $user->forceFill(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Dela Cruz', 'suffix' => null,
                'name' => 'Ana Dela Cruz', 'address' => '12, Coastal Road, Purok 1, Barangay Calayo, Nasugbu, Batangas'])->save();
        }
        $user->profile()->make()->forceFill(['birthdate' => '2000-01-01', 'house_unit_number' => '12',
            'address_street' => 'Coastal Road', 'address_zone_purok' => 'Purok 1', 'civil_status' => 'Single'])->save();

        return $user;
    }

    private function fixture(array $names = ['resident_name', 'resident_age', 'date_issued', 'document_date_full', 'document_day', 'document_day_ordinal', 'document_month', 'document_month_number', 'document_month_abbreviated', 'document_year']): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $resident = $this->resident();
        $service = Service::create(['name' => 'Synthetic Certificate', 'fee_amount' => '0.00']);
        $bytes = CertificatePdf::bytes();
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('a', 40).'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $master = $service->documentTemplate()->create(['original_filename' => 'Official.pdf', 'stored_path' => $path,
            'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'uploaded_at' => now()]);
        $template = $service->dynamicDocumentTemplate()->make();
        $template->forceFill(['service_document_template_id' => $master->id, 'source_revision' => $master->revision(),
            'source_content_hash' => hash('sha256', $bytes), 'source_filename' => 'Official.pdf', 'version' => 1, 'is_active' => true])->save();
        foreach ($names as $index => $name) {
            $field = $template->fields()->create(['field_name' => $name, 'source' => 'system', 'label' => DocumentField::SYSTEM_LABELS[$name],
                'field_type' => DocumentField::SYSTEM_FIELDS[$name], 'is_required' => false, 'sort_order' => $index]);
            $field->positions()->create($this->position($index));
        }
        $schedule = Schedule::create(['date' => now('Asia/Manila')->addDays(2)->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10]);
        $reservation = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => 'approved']);
        $information = app(ReservationDocumentInformation::class);
        $information->store($reservation, $template->load('fields'), [], $information->profile($resident));

        return [$resident, $admin, $service, $template, $reservation];
    }

    private function position(int $index): array
    {
        return ['page_number' => 1, 'x_position' => 40, 'y_position' => 40 + $index * 30, 'width' => 450,
            'height' => 22, 'font_size' => 10, 'alignment' => 'left', 'text_behavior' => 'wrap'];
    }

    public function test_structured_and_legacy_profiles_use_trusted_values_without_guessing(): void
    {
        $information = app(ReservationDocumentInformation::class);
        $resident = $this->resident();
        $profile = $information->profile($resident);
        $this->assertSame('Ana Dela Cruz', $profile['resident_name']);
        $this->assertSame('Ana', $profile['resident_first_name']);
        $this->assertSame('Dela Cruz', $profile['resident_last_name']);
        $this->assertNull($profile['resident_middle_name']);
        $this->assertNull($profile['resident_suffix']);
        $this->assertSame(26, $profile['resident_age']);
        $this->assertSame('2026-11-12', $profile['resident_age_reference_date']);
        $this->assertSame($resident->address, $profile['resident_address']);
        $this->assertSame('12', $profile['resident_house_unit_number']);
        $this->assertSame('Coastal Road', $profile['resident_address_street']);
        $this->assertSame('Purok 1', $profile['resident_address_zone_purok']);
        $this->assertSame('Nasugbu', $profile['municipality_name']);
        $this->assertSame('Batangas', $profile['province_name']);
        $resident->forceFill(['middle_name' => 'Santos', 'suffix' => 'Jr.'])->save();
        $this->assertSame('Ana Santos Dela Cruz Jr.', $information->profile($resident)['resident_name']);
        $legacy = $this->resident(false);
        $legacy->profile()->delete();
        $profile = $information->profile($legacy);
        $this->assertSame('Legacy Name, Kept Whole', $profile['resident_name']);
        $this->assertSame('Legacy complete address', $profile['resident_address']);
        foreach (['resident_first_name', 'resident_middle_name', 'resident_last_name', 'resident_suffix', 'resident_birthdate', 'resident_age', 'resident_house_unit_number'] as $key) {
            $this->assertNull($profile[$key]);
        }
    }

    public function test_registry_designer_save_and_reload_preserve_existing_ids_and_all_new_mappings(): void
    {
        [$resident, $admin, $service, $template] = $this->fixture(['resident_name']);
        $original = $template->fields()->sole();
        $original->update(['label' => 'Original saved label']);
        $this->actingAs($admin);
        $url = route('admin.services.document-layout.edit', $service);
        $page = $this->get($url)->assertOk();
        $fields = collect($page->viewData('designer')['fields']);
        $this->assertCount(count(DocumentField::SYSTEM_FIELDS), $fields);
        $placements = [];
        foreach (array_keys(DocumentField::SYSTEM_FIELDS) as $index => $name) {
            $field = $fields->firstWhere('name', $name);
            $this->assertSame(DocumentField::SYSTEM_FIELDS[$name], $field['type']);
            $this->assertNotNull($field['sample']);
            $placements[] = [...$this->position($index), 'field_id' => $field['field_id'], 'system_name' => $field['system_name']];
        }
        $this->putJson(route('admin.services.document-layout.update', $service), ['version' => 1,
            'pdf_revision' => $template->source_revision, 'placements' => $placements])->assertOk();
        $this->assertSame($original->id, $template->fields()->where('field_name', 'resident_name')->sole()->id);
        $this->assertSame('Original saved label', $original->fresh()->label);
        $this->get($url)->assertOk();
        $ids = $template->fields()->orderBy('id')->pluck('id')->all();
        $this->assertCount(count(DocumentField::SYSTEM_FIELDS), $ids);
        $this->assertSame($ids, $template->fields()->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseCount('document_field_positions', count(DocumentField::SYSTEM_FIELDS));
        $this->assertTrue(Storage::disk('local')->exists($service->documentTemplate->stored_path));
        $this->assertSame(['resident_name' => 'text', 'resident_address' => 'text', 'resident_contact' => 'text',
            'date_issued' => 'date', 'barangay_name' => 'text', 'resident_birthdate' => 'date',
            'resident_civil_status' => 'text', 'resident_occupation' => 'text',
            'resident_address_street' => 'text', 'resident_address_zone_purok' => 'text'],
            array_intersect_key(DocumentField::SYSTEM_FIELDS, array_flip(['resident_name', 'resident_address', 'resident_contact',
                'date_issued', 'barangay_name', 'resident_birthdate', 'resident_civil_status', 'resident_occupation',
                'resident_address_street', 'resident_address_zone_purok'])));
    }

    public function test_required_missing_fields_and_system_answer_override_are_rejected(): void
    {
        [$resident, , , $template] = $this->fixture(['resident_middle_name', 'resident_age']);
        $middle = $template->fields()->where('field_name', 'resident_middle_name')->sole();
        $middle->update(['is_required' => true]);
        $template->load('fields');
        $information = app(ReservationDocumentInformation::class);
        $profile = $information->profile($resident);
        $this->assertSame(['Middle Name'], $information->missingRequiredProfileFields($template, $profile));
        try {
            $information->validate($template, [], $profile);
            $this->fail('Missing required system value was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document_answers.'.$middle->id, $exception->errors());
        }
        $this->expectException(ValidationException::class);
        $information->validate($template, [$middle->id => 'Injected other resident'], $profile);
    }

    public function test_issue_date_is_finalization_date_and_personal_snapshots_stay_fixed(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 15:59:00', 'UTC'));
        [$resident, $admin, , , $reservation] = $this->fixture();
        $before = $reservation->fresh()->getAttributes();
        $this->travelTo(Carbon::parse('2026-12-31 16:01:00', 'UTC'));
        $preview = app(CanonicalDocumentPreview::class);
        $artifact = $preview->create($reservation->fresh(), $admin);
        $this->assertSame('2027-01-01', $artifact['issue_date']);
        $this->assertSame('January 1, 2027', $artifact['date_values']['document_date_full']);
        $this->assertSame('1st', $artifact['date_values']['document_day_ordinal']);
        $bytes = $preview->reviewed($reservation->fresh(), $admin, $artifact['token']);
        $document = app(GeneratedReservationDocuments::class)->generate($reservation->fresh(), $admin, $artifact['token']);
        $this->assertSame('2027-01-01', $document->generated_at->timezone('Asia/Manila')->toDateString());
        $this->assertSame($bytes, app(GeneratedReservationDocuments::class)->bytes($reservation->fresh()));
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        $resident->forceFill(['first_name' => 'Changed', 'name' => 'Changed Name', 'address' => 'Changed Address'])->save();
        $resident->profile()->update(['birthdate' => '1990-01-01', 'address_street' => 'Changed Street']);
        $this->travelTo(Carbon::parse('2028-02-05 12:00:00', 'Asia/Manila'));
        $data = app(ReservationDocumentPreview::class)->data($reservation->fresh());
        $texts = array_column($data['placements'], 'text');
        $this->assertContains('Ana Dela Cruz', $texts);
        $this->assertContains('26', $texts);
        $this->assertContains('January 1, 2027', $texts);
        $this->assertNotContains('Changed Name', $texts);
        $this->assertSame($bytes, app(GeneratedReservationDocuments::class)->bytes($reservation->fresh()));
        $this->actingAs($admin)->get(route('admin.reservations.generated.show', $reservation))->assertOk()->assertSee('January 1, 2027');
        $this->expectException(ValidationException::class);
        app(GeneratedReservationDocuments::class)->generate($reservation->fresh(), $admin, $artifact['token']);
    }

    public function test_midnight_requires_new_review_and_never_silently_rerenders(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 15:59:00', 'UTC'));
        [, $admin, , , $reservation] = $this->fixture();
        $preview = app(CanonicalDocumentPreview::class);
        $artifact = $preview->create($reservation, $admin);
        $bytes = $preview->reviewed($reservation->fresh(), $admin, $artifact['token']);
        $this->travelTo(Carbon::parse('2026-12-31 16:01:00', 'UTC'));
        $this->assertSame($bytes, $preview->reviewed($reservation->fresh(), $admin, $artifact['token']));
        try {
            app(GeneratedReservationDocuments::class)->generate($reservation->fresh(), $admin, $artifact['token']);
            $this->fail('Previous-date preview was finalized.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('reservation_documents', 0);
        }
        $fresh = $preview->create($reservation->fresh(), $admin);
        $this->assertSame('2027-01-01', $fresh['issue_date']);
        try {
            app(GeneratedReservationDocuments::class)->generate($reservation->fresh(), $admin, $fresh['token']);
            $this->fail('Unserved new preview was finalized.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('reservation_documents', 0);
        }
        $reviewed = $preview->reviewed($reservation->fresh(), $admin, $fresh['token']);
        app(GeneratedReservationDocuments::class)->generate($reservation->fresh(), $admin, $fresh['token']);
        $this->assertSame($reviewed, app(GeneratedReservationDocuments::class)->bytes($reservation->fresh()));
    }

    public function test_legacy_snapshot_uses_its_original_date_and_never_live_profile_values(): void
    {
        [, $admin, , , $reservation] = $this->fixture(['resident_name', 'date_issued']);
        $reservation->forceFill(['resident_snapshot' => ['resident_name' => 'Legacy Original', 'date_issued' => '2025-01-02']])->save();
        $data = app(ReservationDocumentPreview::class)->data($reservation);
        $this->assertSame(['Legacy Original', '2025-01-02'], array_column($data['placements'], 'text'));
        $artifact = app(CanonicalDocumentPreview::class)->create($reservation, $admin);
        $this->assertNull($artifact['issue_date']);
        $bytes = app(CanonicalDocumentPreview::class)->reviewed($reservation->fresh(), $admin, $artifact['token']);
        app(GeneratedReservationDocuments::class)->generate($reservation->fresh(), $admin, $artifact['token']);
        $this->assertSame($bytes, app(GeneratedReservationDocuments::class)->bytes($reservation->fresh()));
    }

    public function test_other_resident_and_guest_cannot_see_private_fields_or_preview(): void
    {
        [$resident, $admin, $service, , $reservation] = $this->fixture();
        $url = route('resident.reservations.document-preview.show', $reservation);
        $this->get($url)->assertRedirect(route('login'));
        $other = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->get(route('admin.services.document-layout.edit', $service))->assertForbidden();
        $artifact = app(CanonicalDocumentPreview::class)->create($reservation, $admin);
        $this->get(route('resident.reservations.document-preview.pdf', [$reservation, 'preview' => $artifact['token']]))->assertNotFound();
        $this->actingAs($resident)->get(route('resident.reservations.document-preview.pdf', [$reservation, 'preview' => $artifact['token']]))->assertStatus(410);
        $this->assertFalse(Cache::get('document-preview:'.$artifact['token'])['viewed']);
    }

    public function test_existing_custom_name_collision_stays_custom_and_cannot_be_created_as_a_new_alias(): void
    {
        [, $admin, $service, $template] = $this->fixture(['resident_name']);
        $custom = $template->fields()->create(['field_name' => 'resident_first_name', 'source' => 'custom',
            'field_type' => 'text', 'label' => 'Legacy custom question']);
        $data = ['version' => 1, 'field_name' => 'resident_first_name', 'source' => 'custom', 'field_type' => 'text',
            'label' => 'Updated legacy label', 'is_required' => false, 'sort_order' => 1, 'has_position' => 0];
        $this->actingAs($admin)->put(route('admin.services.document-fields.update', [$service, $custom]), $data)
            ->assertSessionHasNoErrors();
        $this->assertSame('custom', $custom->fresh()->source);
        $this->assertSame('Updated legacy label', $custom->fresh()->label);
        $this->post(route('admin.services.document-fields.store', $service), [...$data, 'version' => 2,
            'field_name' => 'resident_last_name'])->assertSessionHasErrors('field_name');
    }

    public function test_tampering_with_cached_issue_date_never_establishes_review(): void
    {
        [, $admin, , , $reservation] = $this->fixture(['resident_name']);
        $preview = app(CanonicalDocumentPreview::class);
        $artifact = $preview->create($reservation, $admin);
        $artifact['issue_date'] = '2026-11-11';
        Cache::put('document-preview:'.$artifact['token'], $artifact, now()->addMinutes(30));
        try {
            $preview->reviewed($reservation->fresh(), $admin, $artifact['token']);
            $this->fail('Changed preview date was accepted.');
        } catch (ValidationException) {
            $this->assertFalse(Cache::get('document-preview:'.$artifact['token'])['viewed']);
            $this->assertDatabaseCount('reservation_documents', 0);
        }
    }

    public function test_issue_date_values_keep_the_saved_numeric_validation_rules(): void
    {
        [, $admin, , , $reservation] = $this->fixture(['document_year']);
        $answer = $reservation->documentAnswers()->sole();
        $field = $answer->field_snapshot;
        $field['validation_rules'] = ['max' => 2026];
        $answer->update(['field_snapshot' => $field]);
        $this->travelTo(Carbon::parse('2027-01-01 09:00:00', 'Asia/Manila'));
        $this->expectException(ValidationException::class);
        app(CanonicalDocumentPreview::class)->create($reservation->fresh(), $admin);
    }
}
