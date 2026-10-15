<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CanonicalDocumentPreview;
use App\Services\CertificatePreparationState;
use App\Services\FinalCertificatePdf;
use App\Services\GeneratedReservationDocuments;
use App\Services\ReservationDocumentInformation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PdfReader;
use setasign\Fpdi\Tfpdf\Fpdi;
use Tests\TestCase;

class GeneratedReservationDocumentTest extends TestCase
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
        $source = new Fpdi('P', 'pt');
        $source->AddPage('P', [595, 842]);
        $source->SetFont('Helvetica', '', 14);
        $source->Text(40, 40, 'Official master page one');
        $source->AddPage('P', [420, 595]);
        $source->Text(40, 40, 'Official master page two');
        $bytes = $source->Output('S');
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

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function generate($reservation): TestResponse
    {
        $token = null;
        if (auth()->user()?->role === UserRole::Admin && auth()->user()->hasVerifiedEmail() && auth()->user()->is_active) {
            try {
                $artifact = app(CanonicalDocumentPreview::class)->create($reservation->fresh(), auth()->user());
                $token = $artifact['token'];
                app(CanonicalDocumentPreview::class)->reviewed($reservation->fresh(), auth()->user(), $token);
            } catch (ValidationException) {
                // Invalid sources/layouts cannot produce a reviewable preview.
            }
        }

        return $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $token]);
    }

    public function test_generation_access_and_csrf_protection(): void
    {
        [$resident, , , $reservation] = $this->fixture();
        $reservation->update(['status' => 'approved']);
        auth()->logout();
        $this->generate($reservation)->assertRedirect(route('login'));
        $this->actingAs($resident);
        $this->generate($reservation)->assertForbidden();
        $admin = $this->admin();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin);
        $this->generate($reservation)->assertRedirect(route('verification.notice'));
        $admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($admin);
        $this->generate($reservation)->assertRedirect(route('login'));
        $admin->update(['is_active' => true]);
        $this->actingAs($admin);
        $this->app['env'] = 'production';
        $this->generate($reservation)->assertStatus(419);
        $this->assertDatabaseCount('reservation_documents', 0);
    }

    public static function statuses(): array
    {
        return [['pending', false], ['under_review', false], ['approved', true], ['ready_for_pickup', true], ['completed', false], ['rejected', false], ['cancelled', false]];
    }

    #[DataProvider('statuses')]
    public function test_only_approved_workflow_states_can_generate(string $status, bool $allowed): void
    {
        [, , , $reservation] = $this->fixture();
        $reservation->update(['status' => $status]);
        $this->actingAs($this->admin());
        $response = $this->generate($reservation);
        if ($allowed) {
            $response->assertRedirect(route('admin.reservations.show', $reservation))->assertSessionHasNoErrors();
        } else {
            $response->assertForbidden();
        }
        $this->assertDatabaseCount('reservation_documents', $allowed ? 1 : 0);
        $this->assertSame($status, $reservation->fresh()->status->value);
    }

    public function test_pdf_is_historical_private_and_preserves_master_and_business_records(): void
    {
        [$resident, $service, $template, $reservation, $purpose] = $this->fixture();
        $reservation->update(['status' => 'approved']);
        $before = $reservation->fresh()->getAttributes();
        $answers = $reservation->documentAnswers()->get()->toJson();
        $master = Storage::disk('local')->get($service->documentTemplate->stored_path);
        $resident->update(['name' => 'Current profile']);
        $purpose->positions()->update(['x_position' => 400]);
        $template->increment('version');
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->generate($reservation)->assertSessionHasNoErrors();
        $document = $reservation->fresh()->document;
        $this->assertSame(1, (int) $document->template_version);
        $this->assertSame($admin->id, $document->generated_by);
        $this->assertSame($reservation->id, $document->reservation_id);
        $this->assertTrue($document->hasSafePath());
        $bytes = app(GeneratedReservationDocuments::class)->bytes($reservation);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $reader = new Fpdi('P', 'pt');
        $this->assertSame(2, $reader->setSourceFile(Storage::disk('local')->path($document->stored_path)));
        $parser = new PdfParser(StreamReader::createByString($bytes));
        $parsed = new PdfReader($parser);
        $pageOne = $parsed->getPage(1)->getContentStream();
        $pageTwo = $parsed->getPage(2)->getContentStream();
        $this->assertStringContainsString(mb_convert_encoding('Historical Resident', 'UTF-16BE', 'UTF-8'), $pageOne);
        $this->assertStringContainsString(mb_convert_encoding('Historical employment', 'UTF-16BE', 'UTF-8'), $pageOne);
        $this->assertStringContainsString(mb_convert_encoding('Historical employment', 'UTF-16BE', 'UTF-8'), $pageTwo);
        $this->assertStringNotContainsString(mb_convert_encoding('Current profile', 'UTF-16BE', 'UTF-8'), $pageOne);
        $this->assertStringContainsString('72.00 780.00 Td', $pageOne);
        $this->assertTrue(app(CertificatePreparationState::class)->isPrepared($reservation));
        $this->assertSame($master, Storage::disk('local')->get($service->documentTemplate->stored_path));
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        $this->assertSame($answers, $reservation->documentAnswers()->get()->toJson());
        $this->assertDatabaseCount('qr_tickets', 0);
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Preview Generated Document')->assertSee('Template Version')->assertDontSee('Generate Certificate');
        $this->get(route('admin.reservations.generated.show', $reservation))->assertOk()->assertDontSee($document->stored_path, false);
        $this->actingAs($resident)->get(route('resident.reservations.generated.pdf', $reservation))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('resident.reservations.generated.download', $reservation))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="Certificate.pdf"');
        $this->actingAs(User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]));
        foreach (['show', 'pdf', 'download'] as $action) {
            $this->get(route('resident.reservations.generated.'.$action, $reservation))->assertNotFound();
            $this->get(route('admin.reservations.generated.'.$action, $reservation))->assertForbidden();
        }
    }

    public function test_repeated_submission_cannot_overwrite_and_print_uses_generated_pdf_after_master_replacement(): void
    {
        [, $service, , $reservation] = $this->fixture();
        $reservation->update(['status' => 'approved']);
        $this->actingAs($admin = $this->admin());
        $this->generate($reservation)->assertSessionHasNoErrors();
        $document = $reservation->fresh()->document;
        $before = $document->getAttributes();
        $bytes = app(GeneratedReservationDocuments::class)->bytes($reservation);
        $this->generate($reservation)->assertSessionHasErrors('document');
        $this->assertDatabaseCount('reservation_documents', 1);
        $this->assertSame($before, $document->fresh()->getAttributes());
        Storage::disk('local')->delete($service->documentTemplate->stored_path);
        $print = $this->get(route('admin.reservations.document.print', $reservation))->assertRedirect();
        $url = $print->headers->get('Location');
        $this->get($url)->assertOk();
        $this->assertSame($bytes, $this->get($url.'/pdf')->assertOk()->getContent());
        $this->actingAs($this->admin())->get($url)->assertNotFound();
        $this->actingAs($admin);
        Storage::disk('local')->put($document->stored_path, 'tampered');
        $this->get(route('admin.reservations.generated.pdf', $reservation))->assertNotFound();
        $this->get($url.'/pdf')->assertNotFound();
        $this->get(route('admin.reservations.document.print', $reservation))->assertNotFound();
        $this->assertFalse(app(CertificatePreparationState::class)->isPrepared($reservation));
    }

    public function test_missing_snapshots_replaced_source_and_invalid_layout_fail_without_saving(): void
    {
        [, $service, , $reservation] = $this->fixture();
        $reservation->update(['status' => 'approved']);
        $this->actingAs($this->admin());
        $saved = $reservation->document_template_snapshot;
        unset($saved['source']);
        $reservation->forceFill(['document_template_snapshot' => $saved])->save();
        $original = $service->documentTemplate->stored_path;
        $service->documentTemplate->update(['stored_path' => 'service-document-templates/'.$service->id.'/'.str_repeat('b', 40).'.pdf']);
        $this->generate($reservation)->assertSessionHasErrors('document');
        $service->documentTemplate->update(['stored_path' => $original]);
        $answer = $reservation->documentAnswers()->first();
        $snapshot = $answer->field_snapshot;
        $bad = $snapshot;
        $bad['positions'][0]['x_position'] = 9999;
        $answer->update(['field_snapshot' => $bad]);
        $this->generate($reservation)->assertSessionHasErrors('document');
        unset($snapshot['positions']);
        $answer->update(['field_snapshot' => $snapshot]);
        $this->generate($reservation)->assertSessionHasErrors('document');
        $this->assertDatabaseCount('reservation_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('reservation-documents'));
    }

    public function test_four_point_designer_font_is_preserved_by_the_canonical_renderer(): void
    {
        [, $service] = $this->fixture();
        $position = ['page_number' => 1, 'x_position' => 50, 'y_position' => 100, 'width' => 150, 'height' => 6,
            'font_size' => 4, 'alignment' => 'left', 'text_behavior' => 'auto_resize', 'text' => 'Small historical text'];
        $bytes = app(FinalCertificatePdf::class)->render(Storage::disk('local')->path($service->documentTemplate->stored_path), [$position]);
        $reader = new PdfReader(new PdfParser(StreamReader::createByString($bytes)));
        $this->assertStringContainsString('4.00 Tf', $reader->getPage(1)->getContentStream());
    }

    public function test_text_fitting_wrapping_alignment_and_layout_guards(): void
    {
        [, $service] = $this->fixture();
        $renderer = app(FinalCertificatePdf::class);
        $position = ['page_number' => 1, 'x_position' => 50, 'y_position' => 100, 'width' => 150, 'height' => 90, 'font_size' => 20, 'alignment' => 'right', 'text_behavior' => 'auto_resize', 'text' => 'A long historical resident name'];
        $wrapped = [...$position, 'page_number' => 2, 'text_behavior' => 'wrap', 'alignment' => 'center', 'text' => 'Employment supporting document application for Barangay Calayo residents with a long purpose.'];
        $bytes = $renderer->render(Storage::disk('local')->path($service->documentTemplate->stored_path), [$position, $wrapped]);
        $parser = new PdfParser(StreamReader::createByString($bytes));
        $reader = new PdfReader($parser);
        $one = $reader->getPage(1)->getContentStream();
        $two = $reader->getPage(2)->getContentStream();
        preg_match_all('/([0-9.]+) Tf/', $one, $fonts);
        $this->assertLessThan(20, (float) end($fonts[1]));
        $this->assertStringContainsString(mb_convert_encoding($position['text'], 'UTF-16BE', 'UTF-8'), $one);
        $this->assertGreaterThan(1, substr_count($two, ' Tj ET'));
        preg_match_all('/BT ([0-9.]+) ([0-9.]+) Td/', $two, $coordinates, PREG_SET_ORDER);
        foreach ($coordinates as $point) {
            $this->assertGreaterThanOrEqual(50, (float) $point[1]);
            $this->assertLessThanOrEqual(200, (float) $point[1]);
            $this->assertGreaterThanOrEqual(405, (float) $point[2]);
            $this->assertLessThanOrEqual(495, (float) $point[2]);
        }
        foreach ([[$position, $position], [[...$position, 'page_number' => 3]], [[...$position, 'text' => str_repeat('Very long text ', 500), 'width' => 20, 'height' => 10]]] as $invalid) {
            try {
                $renderer->render(Storage::disk('local')->path($service->documentTemplate->stored_path), $invalid);
                $this->fail('Invalid layout must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('document', $exception->errors());
            }
        }
    }
}
