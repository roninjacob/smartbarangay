<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus as Status;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class ServiceCertificateTest extends TestCase
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

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function service(): Service
    {
        $this->actingAs($this->admin())->post('/admin/services', ['name' => 'Barangay Clearance',
            'official_template' => UploadedFile::fake()->createWithContent('Clearance.pdf', CertificatePdf::bytes())])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        return Service::sole();
    }

    private function reservation(Service $service, Status $status = Status::Approved): Reservation
    {
        $schedule = Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 20]);

        return User::factory()->create(['role' => UserRole::Resident])->reservations()->create([
            'service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
    }

    private function assertPrint(string $entry): string
    {
        $response = $this->get($entry)->assertRedirectContains('/admin/certificates/print/');
        $url = $response->headers->get('Location');
        $this->get($url)->assertOk()->assertSee('Print Certificate')->assertSee('data-certificate-browser-print', false)
            ->assertDontSee('DOCX')->assertDontSee('LibreOffice');
        $pdf = $this->get($url.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $pdf->headers->get('Cache-Control'));
        $this->assertSame(CertificatePdf::bytes(), $pdf->getContent());

        return $url;
    }

    public function test_service_prints_exact_pdf_without_any_requests_or_generated_documents(): void
    {
        $service = $this->service();
        $this->get(route('admin.services.certificates.index', $service))->assertOk()->assertSee('Official Certificate PDF')
            ->assertSee('Preview Certificate')->assertSee('Print Certificate')->assertSee('Download PDF')->assertSee('No approved requests');
        $this->assertPrint(route('admin.services.template.print', $service));
        $this->get(route('admin.services.template.preview', $service))->assertOk()->assertStreamedContent(CertificatePdf::bytes());
        $this->get(route('admin.services.template.download', $service))->assertOk()->assertDownload('Clearance.pdf')
            ->assertStreamedContent(CertificatePdf::bytes());
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_documents', 0);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_approval_reveals_service_pdf_and_print_action_without_changing_business_records(): void
    {
        $service = $this->service();
        $reservation = $this->reservation($service, Status::UnderReview);
        $this->get(route('admin.reservations.show', $reservation))->assertDontSee('Print Certificate')->assertDontSee('Certificate Preview');
        $this->patch(route('admin.reservations.status', $reservation), ['status' => 'approved', 'expected_status' => 'under_review'])->assertSessionHas('status');
        $reservation->refresh();
        $requirement = $service->serviceRequirements()->create(['name' => 'Valid ID']);
        $path = 'reservation-attachments/'.$reservation->id.'/'.str_repeat('a', 40).'.pdf';
        Storage::disk('local')->put($path, 'Resident evidence');
        $reservation->attachments()->create(['service_requirement_id' => $requirement->id, 'original_filename' => 'ID.pdf',
            'stored_path' => $path, 'mime_type' => 'application/pdf', 'file_size' => 17]);
        $before = $this->records();
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Certificate Preview')->assertSee('Print Certificate')
            ->assertSee(route('admin.services.template.preview', $service), false);
        $this->assertPrint(route('admin.reservations.document.print', $reservation));
        $this->assertSame($before, $this->records());
        $this->assertSame('Resident evidence', Storage::disk('local')->get($path));
        $this->assertSame(Status::Approved, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_documents', 0);
    }

    private function records(): array
    {
        return collect(['services', 'service_requirements', 'reservations', 'reservation_documents', 'reservation_attachments', 'qr_tickets', 'reservation_status_histories'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public static function nonApproved(): array
    {
        return array_map(fn ($status) => [$status], array_filter(Status::cases(), fn ($status) => $status !== Status::Approved));
    }

    #[DataProvider('nonApproved')]
    public function test_other_statuses_never_expose_print_action_or_endpoint(Status $status): void
    {
        $reservation = $this->reservation($this->service(), $status);
        $this->get(route('admin.reservations.show', $reservation))->assertOk()->assertDontSee('Print Certificate')->assertDontSee('Certificate Preview');
        $this->get(route('admin.reservations.document.print', $reservation))->assertForbidden();
    }

    public function test_ready_warning_requires_reason_and_printing_does_not_bypass_it(): void
    {
        $reservation = $this->reservation($this->service());
        $this->assertPrint(route('admin.reservations.document.print', $reservation));
        $this->get(route('admin.reservations.show', $reservation))->assertSee('No prepared certificate/document was found for this request.')
            ->assertSee('Continue Anyway')->assertSee('data-certificate-prepared="0"', false);
        $data = ['status' => 'ready_for_pickup', 'expected_status' => 'approved', 'confirm_ready' => 1];
        $this->patch(route('admin.reservations.status', $reservation), $data)->assertSessionHasErrors('notes');
        $this->assertSame(Status::Approved, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $this->patch(route('admin.reservations.status', $reservation), $data + ['notes' => 'Certificate was prepared manually.'])->assertSessionHas('status');
        $this->assertSame(Status::ReadyForPickup, $reservation->fresh()->status);
        $this->assertSame('Certificate was prepared manually.', $reservation->statusHistories()->sole()->notes);
    }

    public function test_expiration_ownership_replacement_and_status_changes_invalidate_print_views(): void
    {
        $service = $this->service();
        $reservation = $this->reservation($service);
        $admin = auth()->user();
        $url = $this->assertPrint(route('admin.reservations.document.print', $reservation));
        $this->actingAs($this->admin())->get($url)->assertNotFound();
        $this->actingAs($admin);
        $reservation->update(['status' => Status::ReadyForPickup]);
        $this->get($url)->assertNotFound();
        $reservation->update(['status' => Status::Approved]);
        $this->travel(11)->minutes();
        $this->get($url)->assertNotFound();
        $this->travelBack();
        $url = $this->assertPrint(route('admin.services.template.print', $service));
        $this->put(route('admin.services.update', $service), ['name' => $service->name,
            'official_template' => UploadedFile::fake()->createWithContent('Replacement.pdf', CertificatePdf::bytes('Replacement'))])->assertSessionHasNoErrors();
        $this->get($url)->assertNotFound();
        $this->get($url.'/pdf')->assertNotFound();
    }

    public static function rejectedUploads(): array
    {
        return [['docx'], ['doc'], ['xlsx'], ['pptx'], ['png'], ['jpg'], ['svg'], ['zip'], ['exe'], ['fake-pdf'], ['truncated'], ['no-pages'], ['bad-xref'], ['oversized']];
    }

    #[DataProvider('rejectedUploads')]
    public function test_non_pdf_spoofed_and_corrupt_uploads_preserve_current_file(string $kind): void
    {
        $service = $this->service();
        $before = $service->documentTemplate->getAttributes();
        $contents = match ($kind) {
            'truncated' => substr(CertificatePdf::bytes(), 0, -30),
            'no-pages' => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\nstartxref\n9\n%%EOF\n",
            'bad-xref' => preg_replace('/startxref\s+[0-9]+/', 'startxref 999999', CertificatePdf::bytes()),
            default => 'Fake uploaded contents',
        };
        $file = $kind === 'oversized' ? UploadedFile::fake()->create('too-large.pdf', 10241, 'application/pdf')
            : UploadedFile::fake()->createWithContent('certificate.'.(in_array($kind, ['fake-pdf', 'truncated', 'no-pages', 'bad-xref']) ? 'pdf' : $kind), $contents);
        $this->put(route('admin.services.update', $service), ['name' => 'Changed', 'official_template' => $file])->assertSessionHasErrors('official_template');
        $this->assertSame($before, $service->fresh()->documentTemplate->getAttributes());
        $this->assertSame(CertificatePdf::bytes(), Storage::disk('local')->get($before['stored_path']));
        $this->assertSame('Barangay Clearance', $service->fresh()->name);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_legacy_files_and_records_are_preserved_but_cannot_be_printed_or_downloaded(): void
    {
        $service = $this->service();
        $reservation = $this->reservation($service);
        $template = $service->documentTemplate;
        $legacy = 'service-document-templates/'.$service->id.'/'.str_repeat('b', 40).'.docx';
        Storage::disk('local')->put($legacy, 'Historical document');
        $template->update(['stored_path' => $legacy, 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
        $this->get(route('admin.services.template.preview', $service))->assertNotFound();
        $this->get(route('admin.services.template.download', $service))->assertNotFound();
        $this->get(route('admin.reservations.document.print', $reservation))->assertSessionHasErrors('document');
        $this->assertSame('Historical document', Storage::disk('local')->get($legacy));
        $this->assertDatabaseCount('service_document_templates', 1);
        $this->post('/admin/reservations/'.$reservation->id.'/document')->assertNotFound();
        $this->post('/admin/services/'.$service->id.'/certificates')->assertStatus(405);
    }

    public function test_all_pdf_routes_require_authenticated_active_verified_admin(): void
    {
        $service = $this->service();
        $reservation = $this->reservation($service);
        $url = $this->assertPrint(route('admin.services.template.print', $service));
        $routes = [route('admin.services.certificates.index', $service), route('admin.services.template.preview', $service),
            route('admin.services.template.download', $service), route('admin.services.template.print', $service),
            route('admin.reservations.document.print', $reservation), $url, $url.'/pdf'];
        foreach ($routes as $route) {
            auth()->logout();
            $this->app['auth']->forgetGuards();
            $this->get($route)->assertRedirect(route('login'));
            $this->actingAs(User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]))->get($route)->assertForbidden();
            $admin = $this->admin();
            $admin->forceFill(['email_verified_at' => null])->save();
            $this->actingAs($admin)->get($route)->assertRedirect(route('verification.notice'));
            $admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
            $this->actingAs($admin)->get($route)->assertRedirect(route('login'));
        }
    }

    public function test_ready_status_post_still_requires_csrf(): void
    {
        $reservation = $this->reservation($this->service());
        $this->app->detectEnvironment(fn () => 'local');
        $this->patch(route('admin.reservations.status', $reservation), ['status' => 'ready_for_pickup', 'expected_status' => 'approved', 'confirm_ready' => 1,
            'notes' => 'Prepared manually'])->assertStatus(419);
        $this->assertSame(Status::Approved, $reservation->fresh()->status);
    }

    public function test_historical_prepared_record_is_preserved_and_never_used_as_the_print_source(): void
    {
        $service = $this->service();
        $reservation = $this->reservation($service);
        $temporary = tempnam(sys_get_temp_dir(), 'calayo-history-');
        $zip = new \ZipArchive;
        $zip->open($temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Historical prepared certificate</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $path = 'reservation-documents/'.$reservation->id.'/'.str_repeat('c', 40).'.docx';
        try {
            $bytes = file_get_contents($temporary);
            Storage::disk('local')->put($path, $bytes);
        } finally {
            unlink($temporary);
        }
        $document = $reservation->document()->create(['service_document_template_id' => $service->documentTemplate->id,
            'template_revision' => $service->documentTemplate->revision(), 'template_filename' => 'Historical.docx',
            'original_filename' => 'Historical.docx', 'stored_path' => $path,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size' => strlen($bytes), 'generated_by' => auth()->id(), 'generated_at' => now()]);
        $before = $this->records();
        $this->get(route('admin.reservations.show', $reservation))->assertSee('data-certificate-prepared="1"', false)
            ->assertDontSee('Historical.docx')->assertDontSee('Print prepared certificate');
        $this->assertPrint(route('admin.reservations.document.print', $reservation));
        $this->assertSame($before, $this->records());
        $this->assertSame($bytes, Storage::disk('local')->get($path));
        Storage::disk('local')->put($path, str_repeat('x', strlen($bytes)));
        $this->get(route('admin.reservations.show', $reservation))->assertSee('data-certificate-prepared="0"', false);
        $this->assertEquals($document->getAttributes(), $document->fresh()->getAttributes());
    }

    public function test_detected_mime_and_structure_override_client_claims(): void
    {
        $service = $this->service();
        $original = $service->documentTemplate->stored_path;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6SxAAAAAASUVORK5CYII=');
        $temporary = tempnam(sys_get_temp_dir(), 'calayo-spoof-');
        try {
            file_put_contents($temporary, $png);
            $file = new UploadedFile($temporary, 'certificate.pdf', 'application/pdf', null, true);
            $this->put(route('admin.services.update', $service), ['name' => $service->name, 'official_template' => $file])->assertSessionHasErrors('official_template');
        } finally {
            unlink($temporary);
        }
        $this->put(route('admin.services.update', $service), ['name' => $service->name,
            'official_template' => UploadedFile::fake()->createWithContent('certificate.docx', CertificatePdf::bytes())])->assertSessionHasErrors('official_template');
        $this->put(route('admin.services.update', $service), ['name' => $service->name,
            'official_template' => UploadedFile::fake()->createWithContent('certificate.pdf', preg_replace('/startxref\s+[0-9]+/', "startxref\n1", CertificatePdf::bytes()))])->assertSessionHasErrors('official_template');
        $this->assertSame($original, $service->fresh()->documentTemplate->stored_path);
        $this->assertSame(CertificatePdf::bytes(), Storage::disk('local')->get($original));
    }
}
