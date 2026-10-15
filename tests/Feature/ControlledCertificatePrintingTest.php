<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\QrTicketIssuer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class ControlledCertificatePrintingTest extends TestCase
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

    private function fixture(string $status = 'approved'): Reservation
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->actingAs($admin);
        $service = Service::create(['name' => 'Calayo certificate']);
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('m', 40).'.pdf';
        Storage::disk('local')->put($path, CertificatePdf::bytes('Blank master'));
        $service->documentTemplate()->create(['original_filename' => 'Blank.pdf', 'stored_path' => $path,
            'mime_type' => 'application/pdf', 'file_size' => strlen(CertificatePdf::bytes('Blank master')), 'uploaded_at' => now()]);
        $schedule = Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10]);
        $resident = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);

        return $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
    }

    private function saved(Reservation $reservation): string
    {
        $bytes = CertificatePdf::bytes('Saved personalized request '.$reservation->id);
        $master = $reservation->service->documentTemplate;
        $path = 'reservation-documents/'.$reservation->id.'/'.str_repeat('p', 40).'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $reservation->document()->create(['service_document_template_id' => $master->id, 'template_revision' => $master->revision(),
            'template_version' => 1, 'template_filename' => 'Blank.pdf', 'original_filename' => 'Personalized.pdf',
            'stored_path' => $path, 'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'content_hash' => hash('sha256', $bytes),
            'generated_by' => auth()->id(), 'generated_at' => now()]);
        $reservation->unsetRelation('document');

        return $bytes;
    }

    private function records(): array
    {
        return collect(['services', 'service_document_templates', 'reservations', 'reservation_documents', 'reservation_document_answers',
            'reservation_attachments', 'reservation_status_histories', 'qr_tickets', 'checkin_logs'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public static function histories(): array
    {
        return [['legacy'], ['dynamic'], ['incomplete']];
    }

    #[DataProvider('histories')]
    public function test_no_request_can_fall_back_to_blank_master(string $kind): void
    {
        $reservation = $this->fixture();
        if ($kind === 'dynamic') {
            $reservation->forceFill(['document_template_snapshot' => ['id' => 1, 'version' => 1]])->save();
        } elseif ($kind === 'incomplete') {
            $reservation->documentAnswers()->create(['document_field_id' => null, 'field_snapshot' => ['label' => 'Historical field'], 'answer' => 'Saved answer']);
        }
        $before = $this->records();
        $url = route('admin.reservations.document.print', $reservation);
        $this->get($url.'?source=master&generated=1')->assertRedirect(route('admin.reservations.show', $reservation))->assertSessionHasErrors('document');
        $this->get(route('admin.reservations.show', $reservation))->assertOk()->assertDontSee('href="'.$url.'"', false);
        $this->get(route('admin.services.certificates.index', $reservation->service))->assertOk()->assertDontSee('href="'.$url.'"', false);
        $this->assertSame($before, $this->records());
        $this->assertDatabaseCount('reservation_documents', 0);
    }

    public static function eligible(): array
    {
        return [['approved'], ['ready_for_pickup']];
    }

    #[DataProvider('eligible')]
    public function test_saved_pdf_is_the_only_reservation_source_independent_of_master(string $status): void
    {
        $reservation = $this->fixture($status);
        $bytes = $this->saved($reservation);
        app(QrTicketIssuer::class)->issue($reservation);
        $master = $reservation->service->documentTemplate;
        $replacement = 'service-document-templates/'.$reservation->service_id.'/'.str_repeat('r', 40).'.pdf';
        Storage::disk('local')->put($replacement, CertificatePdf::bytes('New blank master'));
        Storage::disk('local')->delete($master->stored_path);
        $master->update(['stored_path' => $replacement, 'file_size' => strlen(CertificatePdf::bytes('New blank master'))]);
        $replacedUrl = $this->get(route('admin.reservations.document.print', $reservation))->assertRedirect()->headers->get('Location');
        $this->assertSame($bytes, $this->get($replacedUrl.'/pdf')->assertOk()->getContent());
        Storage::disk('local')->delete($replacement);
        $master->delete();
        $before = $this->records();
        $url = $this->get(route('admin.reservations.document.print', $reservation))->assertRedirect()->headers->get('Location');
        $this->get($url)->assertOk()->assertSee('Print Certificate');
        $this->assertSame($bytes, $this->get($url.'/pdf')->assertOk()->getContent());
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Print Certificate')->assertSee('Preview Generated Document');
        if ($status === 'approved') {
            $this->get(route('admin.services.certificates.index', $reservation->service))->assertOk()
                ->assertSee(route('admin.reservations.document.print', $reservation), false);
        }
        $this->assertSame($before, $this->records());
        $this->assertSame($bytes, Storage::disk('local')->get($reservation->fresh()->document->stored_path));
        $this->assertFalse($reservation->qrTicket()->sole()->isUsed());
    }

    public static function invalidOutputs(): array
    {
        return [['missing'], ['tampered'], ['wrong_path'], ['foreign_path'], ['size'], ['hash'], ['mime']];
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_output_never_falls_back_or_repairs_itself(string $kind): void
    {
        $reservation = $this->fixture();
        $this->saved($reservation);
        $document = $reservation->fresh()->document;
        $url = $this->get(route('admin.reservations.document.print', $reservation))->headers->get('Location');
        match ($kind) {
            'missing' => Storage::disk('local')->delete($document->stored_path),
            'tampered' => Storage::disk('local')->put($document->stored_path, str_repeat('x', $document->file_size)),
            'wrong_path' => $document->update(['stored_path' => $reservation->service->documentTemplate->stored_path]),
            'foreign_path' => $document->update(['stored_path' => 'reservation-documents/999/'.str_repeat('p', 40).'.pdf']),
            'size' => $document->update(['file_size' => $document->file_size + 1]),
            'hash' => $document->update(['content_hash' => str_repeat('0', 64)]),
            'mime' => $document->update(['mime_type' => 'text/plain']),
        };
        $before = $this->records();
        $files = Storage::disk('local')->allFiles();
        $this->get(route('admin.reservations.document.print', $reservation))->assertNotFound();
        $this->get($url)->assertNotFound();
        $this->get($url.'/pdf')->assertNotFound();
        $this->assertSame($before, $this->records());
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_old_reservation_master_tokens_are_rejected_before_and_after_generation(): void
    {
        $reservation = $this->fixture();
        $master = $reservation->service->documentTemplate;
        $token = str_repeat('o', 40);
        Cache::put('certificate-print:'.$token, ['owner' => auth()->id(), 'pdf' => base64_encode(CertificatePdf::bytes('Blank master')),
            'service' => $reservation->service->name, 'service_id' => $reservation->service_id, 'reservation_id' => $reservation->id,
            'source_path' => $master->stored_path, 'source_hash' => hash('sha256', CertificatePdf::bytes('Blank master'))], now()->addMinutes(10));
        foreach (['absent', 'generated', 'missing'] as $state) {
            if ($state === 'generated') {
                $this->saved($reservation);
            } elseif ($state === 'missing') {
                Storage::disk('local')->delete($reservation->fresh()->document->stored_path);
            }
            foreach (['', '/pdf'] as $suffix) {
                $this->get(route('admin.certificates.print.show', $token).$suffix)->assertStatus(410)->assertSee('Reopen Reservation Details')
                    ->assertDontSee('Blank master')->assertDontSee($master->stored_path, false);
            }
        }
        $this->assertTrue(Cache::has('certificate-print:'.$token));
        $this->assertDatabaseCount('reservation_documents', 1);
    }

    public function test_generated_tokens_revalidate_owner_expiry_document_identity_and_status(): void
    {
        $reservation = $this->fixture();
        $this->saved($reservation);
        $admin = auth()->user();
        $url = $this->get(route('admin.reservations.document.print', $reservation))->headers->get('Location');
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]));
        foreach (['', '/pdf'] as $suffix) {
            $this->get($url.$suffix)->assertNotFound();
        }
        $this->actingAs($admin);
        $reservation->update(['status' => 'completed']);
        $this->get($url)->assertNotFound();
        $this->get($url.'/pdf')->assertNotFound();
        $reservation->update(['status' => 'approved']);
        $token = basename($url);
        $artifact = Cache::get('certificate-print:'.$token);
        $artifact['generated_id']++;
        Cache::put('certificate-print:'.$token, $artifact, now()->addMinutes(10));
        $this->get($url)->assertNotFound();
        $this->get($url.'/pdf')->assertNotFound();
        $url = $this->get(route('admin.reservations.document.print', $reservation))->headers->get('Location');
        $this->travel(11)->minutes();
        $this->get($url)->assertNotFound();
        $this->get($url.'/pdf')->assertNotFound();
        $this->travelBack();
    }

    public function test_standalone_blank_tools_have_distinct_labels_and_serve_master(): void
    {
        $reservation = $this->fixture();
        $service = $reservation->service;
        $this->get(route('admin.services.index'))->assertSee('Preview Blank Template')->assertSee('Print Blank Template')->assertSee('Download Blank PDF');
        $this->get(route('admin.services.certificates.index', $service))->assertSee('Print Blank Template')->assertSee('Download Blank PDF');
        $url = $this->get(route('admin.services.template.print', $service))->headers->get('Location');
        $this->assertSame(CertificatePdf::bytes('Blank master'), $this->get($url.'/pdf')->assertOk()->getContent());
        $this->get($url)->assertOk()->assertSee('Print Blank Template');
        $this->assertDatabaseCount('reservation_documents', 0);
    }

    public function test_generated_printing_remains_admin_only_and_resident_history_access_is_unchanged(): void
    {
        $reservation = $this->fixture();
        $bytes = $this->saved($reservation);
        $owner = $reservation->user;
        $admin = auth()->user();
        $entry = route('admin.reservations.document.print', $reservation);
        $url = $this->get($entry)->headers->get('Location');
        foreach ([$entry, $url, $url.'/pdf'] as $route) {
            auth()->logout();
            $this->app['auth']->forgetGuards();
            $this->get($route)->assertRedirect(route('login'));
            $this->actingAs($owner)->get($route)->assertForbidden();
            $admin->forceFill(['email_verified_at' => null, 'is_active' => true])->save();
            $this->actingAs($admin)->get($route)->assertRedirect(route('verification.notice'));
            $admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
            $this->actingAs($admin)->get($route)->assertRedirect(route('login'));
        }
        $reservation->update(['status' => 'completed']);
        $this->actingAs($owner);
        $this->assertSame($bytes, $this->get(route('resident.reservations.generated.pdf', $reservation))->assertOk()->getContent());
        $this->get(route('resident.reservations.generated.show', $reservation))->assertOk();
        $this->get(route('resident.reservations.generated.download', $reservation))->assertOk();
        $this->actingAs(User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]));
        foreach (['show', 'pdf', 'download'] as $action) {
            $this->get(route('resident.reservations.generated.'.$action, $reservation))->assertNotFound();
        }
    }

    public function test_lightweight_row_hint_never_bypasses_authoritative_integrity_checks(): void
    {
        $reservation = $this->fixture();
        $this->saved($reservation);
        // Real retrieval still verifies bytes, but a list should only inspect existence and size.
        Storage::disk('local')->put($reservation->fresh()->document->stored_path, str_repeat('x', $reservation->document->file_size));
        $response = $this->get(route('admin.services.certificates.index', $reservation->service))->assertOk();
        $response->assertSee(route('admin.reservations.document.print', $reservation), false);
        $this->get(route('admin.reservations.document.print', $reservation))->assertNotFound();
        $this->get(route('admin.reservations.show', $reservation))->assertOk()
            ->assertSee('saved certificate is unavailable or invalid')
            ->assertDontSee('href="'.route('admin.reservations.document.print', $reservation).'"', false);
    }
}
