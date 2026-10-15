<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CanonicalDocumentPreview;
use App\Services\ReservationDocumentInformation;
use App\Services\ReservationDocumentPreview;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class ReservationDocumentPreviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true, 'session.driver' => 'array', 'cache.default' => 'array']);
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
        $resident = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true, 'name' => 'Historical Resident', 'address' => 'Historical address']);
        $service = Service::create(['name' => 'Generic document']);
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('a', 40).'.pdf';
        $bytes = CertificatePdf::twoPages();
        Storage::disk('local')->put($path, $bytes);
        $pdf = $service->documentTemplate()->create(['original_filename' => 'Official.pdf', 'stored_path' => $path, 'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'uploaded_at' => now()]);
        $template = $service->dynamicDocumentTemplate()->make();
        $template->forceFill(['service_document_template_id' => $pdf->id, 'source_revision' => $pdf->revision(), 'source_content_hash' => hash('sha256', $bytes), 'source_filename' => 'Official.pdf', 'version' => 1, 'is_active' => true])->save();
        $name = $template->fields()->create(['field_name' => 'resident_name', 'label' => 'Historical name label', 'source' => 'system', 'field_type' => 'text']);
        $purpose = $template->fields()->create(['field_name' => 'purpose', 'label' => 'Historical purpose label', 'source' => 'custom', 'field_type' => 'text']);
        foreach ([[$name, 1, 50], [$purpose, 1, 100], [$purpose, 2, 150]] as [$field,$page,$y]) {
            $field->positions()->create(['page_number' => $page, 'x_position' => 72, 'y_position' => $y, 'width' => 180, 'height' => 30, 'font_size' => 12, 'alignment' => 'left', 'text_behavior' => $field === $name ? 'auto_resize' : 'wrap']);
        }
        $schedule = Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 5]);
        $reservation = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => 'pending']);
        $information = app(ReservationDocumentInformation::class);
        $information->store($reservation, $template->load('fields'), [$purpose->id => 'Historical employment'], $information->profile($resident));
        $this->actingAs($resident);

        return [$resident, $service, $template, $reservation, $purpose];
    }

    public static function routes(): array
    {
        return [['resident', ''], ['resident', '/pdf'], ['admin', ''], ['admin', '/pdf']];
    }

    #[DataProvider('routes')]
    public function test_protected_preview_endpoints(string $role, string $suffix): void
    {
        [$resident,$service,$template,$reservation] = $this->fixture();
        $artifact = app(CanonicalDocumentPreview::class)->create($reservation, $resident);
        $url = '/'.$role.'/reservations/'.$reservation->id.'/document-preview'.$suffix.'?preview='.$artifact['token'];
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
        $user = $role === 'resident' ? $resident : User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        if ($role === 'admin' && $suffix) {
            $artifact = app(CanonicalDocumentPreview::class)->create($reservation, $user);
            $url = '/admin/reservations/'.$reservation->id.'/document-preview/pdf?preview='.$artifact['token'];
        }
        $this->actingAs($user)->get($url)->assertOk();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->get($url)->assertRedirect(route('verification.notice'));
        $user->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($user)->get($url)->assertRedirect(route('login'));
    }

    public function test_foreign_resident_is_blocked_for_page_and_pdf_and_wrong_roles_remain_forbidden(): void
    {
        [$resident,$service,$template,$reservation] = $this->fixture();
        $other = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $this->actingAs($other);
        foreach (['', '/pdf'] as $suffix) {
            $this->get('/resident/reservations/'.$reservation->id.'/document-preview'.$suffix)->assertNotFound();
            $this->get('/admin/reservations/'.$reservation->id.'/document-preview'.$suffix)->assertForbidden();
        }
    }

    public function test_profile_field_and_layout_edits_or_deletion_do_not_change_historical_data(): void
    {
        [$resident,$service,$template,$reservation,$purpose] = $this->fixture();
        $preview = app(ReservationDocumentPreview::class);
        $before = $preview->data($reservation);
        $resident->update(['name' => 'Current name', 'address' => 'Current address']);
        $purpose->update(['label' => 'Changed label']);
        $purpose->positions()->update(['x_position' => 400]);
        $template->increment('version');
        $purpose->delete();
        $after = $preview->data($reservation->fresh());
        $this->assertSame($before, $after);
        $this->assertCount(3, $after['placements']);
        $this->assertSame([1, 1, 2], array_column($after['placements'], 'page_number'));
        $this->assertSame('Historical Resident', $after['placements'][0]['text']);
        $this->assertSame('Historical employment', $after['placements'][1]['text']);
        $this->get('/resident/reservations/'.$reservation->id.'/document-preview')->assertOk()->assertSee('Historical purpose label')->assertDontSee('Current address')->assertDontSee($service->documentTemplate->stored_path, false);
    }

    public function test_missing_legacy_layout_or_replaced_pdf_is_unavailable_without_fallback(): void
    {
        [$resident,$service,$template,$reservation] = $this->fixture();
        $saved = $reservation->document_template_snapshot;
        unset($saved['source']);
        $reservation->forceFill(['document_template_snapshot' => $saved])->save();
        $path = $service->documentTemplate->stored_path;
        $service->documentTemplate->update(['stored_path' => 'service-document-templates/'.$service->id.'/'.str_repeat('b', 40).'.pdf']);
        $this->get('/resident/reservations/'.$reservation->id.'/document-preview')->assertSee(ReservationDocumentPreview::UNAVAILABLE);
        $this->get('/resident/reservations/'.$reservation->id.'/document-preview/pdf?revision='.$template->source_revision)->assertStatus(410);
        $service->documentTemplate->update(['stored_path' => $path]);
        $answer = $reservation->documentAnswers()->first();
        $snapshot = $answer->field_snapshot;
        unset($snapshot['positions']);
        $answer->update(['field_snapshot' => $snapshot]);
        $this->assertNull(app(ReservationDocumentPreview::class)->data($reservation->fresh()));
    }

    public function test_preview_is_read_only_and_serves_original_private_pdf_with_revision_guard(): void
    {
        [$resident,$service,$template,$reservation] = $this->fixture();
        $before = $reservation->fresh()->getAttributes();
        $answers = $reservation->documentAnswers()->get()->toJson();
        $disk = Storage::disk('local');
        $artifact = app(CanonicalDocumentPreview::class)->create($reservation, $resident);
        $files = $disk->allFiles();
        $bytes = $disk->get($artifact['path']);
        $url = '/resident/reservations/'.$reservation->id.'/document-preview/pdf';
        $response = $this->get($url.'?preview='.$artifact['token'])->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($bytes, $response->getContent());
        $this->get($url.'?preview=wrong')->assertStatus(410);
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        $this->assertSame($answers, $reservation->documentAnswers()->get()->toJson());
        $this->assertSame($files, $disk->allFiles());
        $this->assertDatabaseCount('reservation_documents', 0);
        $this->assertDatabaseCount('qr_tickets', 0);
        $this->assertDatabaseCount('checkin_logs', 0);
    }
}
