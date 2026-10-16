<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CanonicalDocumentPreview;
use App\Services\DocumentTemplateConfiguration;
use App\Services\GeneratedReservationDocuments;
use App\Services\ReservationDocumentInformation;
use App\Services\ReservationDocumentPreview;
use App\Services\ServiceDocumentTemplates;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class DocumentAutomationHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true,
            'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Storage::fake('local');
        $this->withoutVite();
    }

    private function fixture(): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $resident = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true, 'name' => 'Historical Resident']);
        $service = Service::create(['fee_amount' => '0.00', 'name' => 'Historical Certificate']);
        $bytes = CertificatePdf::twoPages();
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('a', 40).'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $master = $service->documentTemplate()->create(['original_filename' => 'A.pdf', 'stored_path' => $path,
            'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'uploaded_at' => now()]);
        app(DocumentTemplateConfiguration::class)->create($service, ['pdf_id' => $master->id,
            'pdf_revision' => $master->revision(), 'is_active' => true], $admin->id);
        $template = $service->dynamicDocumentTemplate()->first();
        $field = $template->fields()->create(['field_name' => 'resident_name', 'source' => 'system',
            'label' => 'Resident Name', 'field_type' => 'text', 'is_required' => true]);
        $field->positions()->create(['page_number' => 1, 'x_position' => 40, 'y_position' => 90,
            'width' => 230, 'height' => 30, 'font_size' => 12, 'alignment' => 'left', 'text_behavior' => 'wrap']);
        // Fields without printable placements are explicitly supported as workflow-only information.
        $template->fields()->create(['field_name' => 'date_issued', 'source' => 'system', 'label' => 'Date Issued', 'field_type' => 'date']);
        $schedule = Schedule::create(['date' => '2026-12-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10]);
        $reservation = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => 'approved']);
        app(ReservationDocumentInformation::class)->store($reservation, $template->load('fields'), [], app(ReservationDocumentInformation::class)->profile($resident));
        $this->actingAs($admin);

        return [$admin, $resident, $service, $template, $reservation];
    }

    private function review(Reservation $reservation, User $admin): array
    {
        $preview = app(CanonicalDocumentPreview::class);
        $artifact = $preview->create($reservation, $admin);
        $bytes = $preview->reviewed($reservation->fresh(), $admin, $artifact['token']);

        return [$artifact, $bytes];
    }

    public function test_head_cannot_review_a_preview_but_get_allows_exact_byte_finalization(): void
    {
        [, , , , $reservation] = $this->fixture();
        $before = $reservation->fresh()->getAttributes();
        $artifact = $this->get(route('admin.reservations.document-preview.show', $reservation))->assertOk()->viewData('artifact');
        $key = 'document-preview:'.$artifact['token'];
        $pdf = route('admin.reservations.document-preview.pdf', [$reservation, 'preview' => $artifact['token']]);
        $finalize = route('admin.reservations.generated.store', $reservation);
        $input = ['preview_token' => $artifact['token']];
        $bytes = Storage::disk('local')->get($artifact['path']);

        $this->assertFalse(Cache::get($key)['viewed']);
        $this->post($finalize, $input)->assertSessionHasErrors('document');
        $this->assertDatabaseCount('reservation_documents', 0);
        $this->head($pdf)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertContent('');
        $this->assertSame($artifact, Cache::get($key));
        $this->post($finalize, $input)->assertSessionHasErrors('document');
        $this->assertDatabaseCount('reservation_documents', 0);

        $this->get($pdf)->assertOk()->assertContent($bytes);
        $reviewed = Cache::get($key);
        $this->assertTrue($reviewed['viewed']);
        $this->head($pdf)->assertOk()->assertContent('');
        $this->assertSame($reviewed, Cache::get($key));
        $this->post($finalize, $input)->assertRedirect(route('admin.reservations.show', $reservation))->assertSessionHasNoErrors();
        $this->assertSame($bytes, app(GeneratedReservationDocuments::class)->bytes($reservation->fresh()));
        $this->assertSame(hash('sha256', $bytes), $reservation->fresh()->document->content_hash);
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->assertDatabaseCount('qr_tickets', 0);
        $this->post($finalize, $input)->assertSessionHasErrors('document');
        $this->assertDatabaseCount('reservation_documents', 1);
    }

    public function test_resident_preview_head_also_leaves_review_state_unchanged(): void
    {
        [, $resident, , , $reservation] = $this->fixture();
        $this->actingAs($resident);
        $artifact = $this->get(route('resident.reservations.document-preview.show', $reservation))->assertOk()->viewData('artifact');
        $key = 'document-preview:'.$artifact['token'];
        $pdf = route('resident.reservations.document-preview.pdf', [$reservation, 'preview' => $artifact['token']]);
        $this->head($pdf)->assertOk()->assertContent('');
        $this->assertSame($artifact, Cache::get($key));
        $this->get($pdf)->assertOk()->assertContent(Storage::disk('local')->get($artifact['path']));
        $this->assertTrue(Cache::get($key)['viewed']);
        $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $artifact['token']])->assertForbidden();
        $this->assertDatabaseCount('reservation_documents', 0);
    }

    public static function invalidPreviewReads(): array
    {
        $cases = [];
        foreach (['HEAD', 'GET'] as $method) {
            foreach (['account', 'reservation', 'expired', 'invalid_token', 'fingerprint', 'missing', 'size', 'hash', 'source'] as $failure) {
                $cases[$method.' '.$failure] = [$method, $failure];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidPreviewReads')]
    public function test_invalid_protected_pdf_requests_never_establish_review(string $method, string $failure): void
    {
        [$admin, $resident, $service, $template, $reservation] = $this->fixture();
        $artifact = $this->get(route('admin.reservations.document-preview.show', $reservation))->assertOk()->viewData('artifact');
        $key = 'document-preview:'.$artifact['token'];
        $target = $reservation;
        $token = $artifact['token'];
        $disk = Storage::disk('local');
        if ($failure === 'account') {
            $this->actingAs(User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]));
        } elseif ($failure === 'reservation') {
            $target = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $reservation->schedule_id, 'status' => 'approved']);
            app(ReservationDocumentInformation::class)->store($target, $template->load('fields'), [], app(ReservationDocumentInformation::class)->profile($resident));
        } elseif ($failure === 'expired') {
            $artifact['expires'] = now()->subSecond()->timestamp;
            Cache::put($key, $artifact, now()->addMinutes(30));
        } elseif ($failure === 'invalid_token') {
            $token = str_repeat('z', 40);
        } elseif ($failure === 'fingerprint') {
            $snapshot = $reservation->resident_snapshot;
            $snapshot['resident_name'] = 'Changed historical value';
            $reservation->forceFill(['resident_snapshot' => $snapshot])->save();
        } elseif ($failure === 'missing') {
            $disk->delete($artifact['path']);
        } elseif ($failure === 'size') {
            $disk->append($artifact['path'], 'changed');
        } elseif ($failure === 'hash') {
            $disk->put($artifact['path'], str_repeat('x', $artifact['size']));
        } elseif ($failure === 'source') {
            $source = $reservation->document_template_snapshot['source'];
            $disk->put($source['path'], str_repeat('x', $source['size']));
        }

        $this->call($method, route('admin.reservations.document-preview.pdf', [$target, 'preview' => $token]))->assertStatus(410);
        $this->assertSame($artifact, Cache::get($key));
        $this->assertFalse(Cache::get($key)['viewed']);
        $this->actingAs($admin)->post(route('admin.reservations.generated.store', $target), ['preview_token' => $token])->assertSessionHasErrors('document');
        $this->assertDatabaseCount('reservation_documents', 0);
    }

    public function test_historical_master_survives_real_replacement_and_exact_reviewed_bytes_are_finalized(): void
    {
        [$admin, $resident, $service, $template, $reservation] = $this->fixture();
        $before = $reservation->fresh()->getAttributes();
        [$artifact, $previewBytes] = $this->review($reservation, $admin);
        $newPaths = [];
        DB::transaction(function () use ($service, $admin, &$newPaths, &$old) {
            Service::lockForUpdate()->findOrFail($service->id);
            $old = app(ServiceDocumentTemplates::class)->replace($service,
                UploadedFile::fake()->createWithContent('B.pdf', CertificatePdf::bytes('Different Master B')), $admin->id, $newPaths);
        });
        app(ServiceDocumentTemplates::class)->cleanup([$old]);
        $this->assertFalse(Storage::disk('local')->exists($old));
        $this->assertNotNull(app(ReservationDocumentPreview::class)->data($reservation->fresh()));
        $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $artifact['token']])->assertSessionHasNoErrors();
        $saved = app(GeneratedReservationDocuments::class)->bytes($reservation->fresh());
        $this->assertSame($previewBytes, $saved);
        $this->assertSame(hash('sha256', $previewBytes), $reservation->fresh()->document->content_hash);
        $this->assertNull($reservation->fresh()->document->service_document_template_id);
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $this->assertDatabaseCount('qr_tickets', 0);
        $this->assertDatabaseCount('checkin_logs', 0);
        $print = $this->get(route('admin.reservations.document.print', $reservation))->assertRedirect();
        $this->assertSame($saved, $this->get($print->headers->get('Location').'/pdf')->assertOk()->getContent());
        $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $artifact['token']])->assertSessionHasErrors('document');
        $this->assertDatabaseCount('reservation_documents', 1);
        $this->actingAs($resident)->get(route('resident.reservations.generated.pdf', $reservation))->assertOk();
    }

    public function test_stale_source_blocks_mid_wizard_submission_and_explicit_reconfiguration_clears_old_placements(): void
    {
        [$admin, $resident, $service, $template, $reservation] = $this->fixture();
        $information = app(ReservationDocumentInformation::class);
        $this->actingAs($resident);
        $this->post('/resident/reservations/service', ['service_id' => $service->id])->assertRedirect();
        $this->post('/resident/reservations/requirements', ['document_signature' => $information->signature($template->fresh('fields'))])->assertRedirect();
        $this->post('/resident/reservations/schedule', ['schedule_id' => $reservation->schedule_id])->assertRedirect();
        $token = session('reservation_wizard.confirmation_token');
        $disk = Storage::disk('local');
        $master = $service->documentTemplate;
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('b', 40).'.pdf';
        $bytes = CertificatePdf::twoPages();
        $disk->put($path, $bytes);
        $master->update(['stored_path' => $path, 'file_size' => strlen($bytes)]);
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.create'))->assertSessionHasErrors('reservation');
        $this->assertDatabaseCount('reservations', 1);
        $this->get('/resident/reservations/requirements')->assertRedirect();
        $this->actingAs($admin);
        $this->get('/admin/services/'.$service->id.'/document-configuration')->assertSee('Needs reconfiguration')->assertSee('Reconfigure for Current Master');
        app(DocumentTemplateConfiguration::class)->reconfigure($service->fresh(), ['version' => $template->version,
            'pdf_id' => $master->id, 'pdf_revision' => $master->revision()]);
        $this->assertDatabaseCount('document_field_positions', 0);
        $this->assertFalse($template->fresh()->is_active);
        $this->assertTrue($template->fresh()->matchesCurrentPdf());
        // Explicitly review a new placement and activate B. A's snapshots remain unchanged.
        $field = $template->fields()->where('field_name', 'resident_name')->first();
        $field->positions()->create(['page_number' => 1, 'x_position' => 60, 'y_position' => 110, 'width' => 200, 'height' => 30,
            'font_size' => 12, 'alignment' => 'left', 'text_behavior' => 'wrap']);
        app(DocumentTemplateConfiguration::class)->mutate($service->fresh(), $template->fresh()->version, fn ($t) => $t->fill(['is_active' => true]));
        $this->assertNotNull($information->configuration($service->fresh()));
        $new = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $reservation->schedule_id, 'status' => 'pending']);
        $information->store($new, $template->fresh('fields'), [], $information->profile($resident));
        $this->assertNotSame($reservation->document_template_snapshot['source_revision'], $new->document_template_snapshot['source_revision']);
        $this->assertNotSame($reservation->document_template_snapshot['source']['path'], $new->document_template_snapshot['source']['path']);
    }

    public function test_preview_tokens_are_owner_reservation_expiry_and_fingerprint_bound(): void
    {
        [$admin, , , , $reservation] = $this->fixture();
        [$artifact] = $this->review($reservation, $admin);
        $other = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $url = route('admin.reservations.document-preview.pdf', [$reservation, 'preview' => $artifact['token']]);
        $this->actingAs($other)->get($url)->assertStatus(410);
        $this->actingAs($admin);
        $snapshot = $reservation->resident_snapshot;
        $snapshot['resident_name'] = 'Changed historical input';
        $reservation->forceFill(['resident_snapshot' => $snapshot])->save();
        $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $artifact['token']])->assertSessionHasErrors('document');
        $this->get($url)->assertStatus(410);
        [$fresh] = $this->review($reservation->fresh(), $admin);
        $this->travel(31)->minutes();
        $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $fresh['token']])->assertSessionHasErrors('document');
        $this->assertDatabaseCount('reservation_documents', 0);
    }

    public function test_unserved_preview_or_tampered_preview_cannot_finalize_and_current_profile_is_irrelevant(): void
    {
        [$admin, $resident, , , $reservation] = $this->fixture();
        $artifact = app(CanonicalDocumentPreview::class)->create($reservation, $admin);
        $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $artifact['token']])->assertSessionHasErrors('document');
        $this->get(route('admin.reservations.document-preview.pdf', [$reservation, 'preview' => $artifact['token']]))->assertOk();
        $resident->update(['name' => 'Future profile']);
        $bytes = Storage::disk('local')->get($artifact['path']);
        Storage::disk('local')->put($artifact['path'], str_repeat('x', strlen($bytes)));
        $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $artifact['token']])->assertSessionHasErrors('document');
        Storage::disk('local')->put($artifact['path'], $bytes);
        $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $artifact['token']])->assertSessionHasNoErrors();
        $this->assertSame($bytes, app(GeneratedReservationDocuments::class)->bytes($reservation->fresh()));
    }

    public function test_source_tampering_missing_or_foreign_path_never_falls_back_to_master(): void
    {
        [$admin, , , , $reservation] = $this->fixture();
        [$artifact] = $this->review($reservation, $admin);
        $snapshot = $reservation->document_template_snapshot;
        Storage::disk('local')->put($snapshot['source']['path'], str_repeat('x', $snapshot['source']['size']));
        $this->assertNull(app(ReservationDocumentPreview::class)->data($reservation->fresh()));
        $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $artifact['token']])->assertSessionHasErrors('document');
        $snapshot['source']['path'] = str_replace('/'.$reservation->id.'/', '/9999/', $snapshot['source']['path']);
        $reservation->forceFill(['document_template_snapshot' => $snapshot])->save();
        $this->assertNull(app(ReservationDocumentPreview::class)->data($reservation->fresh()));
        $this->assertDatabaseCount('reservation_documents', 0);
    }

    public function test_layout_save_rejects_overlap_and_bounds_but_accepts_edge_contact_and_different_pages(): void
    {
        [, , $service, $template] = $this->fixture();
        $field = $template->fields()->first();
        $box = ['field_id' => $field->id, 'page_number' => 1, 'x_position' => 0.1, 'y_position' => 0.2,
            'width' => 20.2, 'height' => 20.3, 'font_size' => 4, 'alignment' => 'left', 'text_behavior' => 'wrap'];
        $url = '/admin/services/'.$service->id.'/document-layout';
        $save = fn ($positions) => $this->putJson($url, ['version' => $template->fresh()->version, 'pdf_revision' => $template->source_revision, 'placements' => $positions]);
        $save([$box, [...$box, 'x_position' => 20.299]])->assertUnprocessable()->assertJsonValidationErrors('layout');
        $this->assertSame(1, $template->fresh()->version);
        $save([$box, [...$box, 'x_position' => 20.3]])->assertOk();
        $save([$box, [...$box, 'x_position' => 20.3, 'y_position' => 20.5]])->assertOk();
        $save([$box, [...$box, 'page_number' => 2]])->assertOk();
        $save([[...$box, 'page_number' => 3]])->assertUnprocessable();
        $save([[...$box, 'x_position' => 9999]])->assertUnprocessable();
    }

    public function test_same_path_source_change_is_stale_and_legacy_without_hash_evidence_fails_closed(): void
    {
        [$admin, , $service, $template, $reservation] = $this->fixture();
        $disk = Storage::disk('local');
        $disk->put($service->documentTemplate->stored_path, CertificatePdf::bytes('Changed same-path source'));
        $this->assertFalse($template->fresh()->matchesCurrentPdf());
        $this->assertNotNull(app(ReservationDocumentPreview::class)->data($reservation->fresh()));
        $snapshot = $reservation->document_template_snapshot;
        unset($snapshot['source']);
        $reservation->forceFill(['document_template_snapshot' => $snapshot])->save();
        $this->assertNull(app(ReservationDocumentPreview::class)->data($reservation->fresh()));
        $template->forceFill(['source_content_hash' => null])->save();
        $this->get('/admin/services/'.$service->id.'/document-configuration')->assertSee('Needs reconfiguration');
    }

    public function test_preview_token_cannot_be_used_for_a_different_reservation_or_resident(): void
    {
        [$admin, , $service, , $reservation] = $this->fixture();
        [$artifact] = $this->review($reservation, $admin);
        $foreign = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $other = $foreign->reservations()->create(['service_id' => $service->id, 'schedule_id' => $reservation->schedule_id, 'status' => 'approved']);
        $this->post(route('admin.reservations.generated.store', $other), ['preview_token' => $artifact['token']])->assertSessionHasErrors('document');
        $this->actingAs($foreign)->get(route('resident.reservations.document-preview.pdf', [$reservation, 'preview' => $artifact['token']]))->assertNotFound();
        $this->assertDatabaseCount('reservation_documents', 0);
    }

    public function test_incomplete_template_snapshot_cannot_offer_a_finalizable_preview(): void
    {
        [, , , , $reservation] = $this->fixture();
        $snapshot = $reservation->document_template_snapshot;
        foreach (['id', 'version', 'source_filename'] as $key) {
            $bad = $snapshot;
            unset($bad[$key]);
            $reservation->forceFill(['document_template_snapshot' => $bad])->save();
            $this->assertNull(app(ReservationDocumentPreview::class)->data($reservation->fresh()));
        }
    }

    public function test_cleanup_never_removes_active_preview_or_historical_and_generated_files(): void
    {
        [$admin, , , , $reservation] = $this->fixture();
        [$old] = $this->review($reservation, $admin);
        $this->travel(25)->hours();
        // File age and token expiry are independent; safely simulate an old abandoned file.
        touch(Storage::disk('local')->path($old['path']), now()->subHours(25)->timestamp);
        [$active] = $this->review($reservation->fresh(), $admin);
        $source = $reservation->document_template_snapshot['source']['path'];
        $this->assertSame(1, app(CanonicalDocumentPreview::class)->cleanup());
        $this->assertTrue(Storage::disk('local')->exists($active['path']));
        $this->assertTrue(Storage::disk('local')->exists($source));
        $this->assertFalse(Storage::disk('local')->exists($old['path']));
    }
}
