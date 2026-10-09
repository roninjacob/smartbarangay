<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CertificatePdf;
use Tests\TestCase;
use ZipArchive;

class ServiceDocumentTemplateTest extends TestCase
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
        Storage::fake('public');
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public static function formats(): array
    {
        return [['pdf']];
    }

    #[DataProvider('formats')]
    public function test_create_service_with_private_template_and_secure_download(string $format): void
    {
        $admin = $this->admin();
        $file = $this->pdf();
        $this->actingAs($admin)->post('/admin/services', ['name' => 'Clearance', 'official_template' => $file,
            'uploaded_by' => 999, 'stored_path' => '../../secret', 'service_id' => 999])->assertSessionHas('status');
        $service = Service::sole();
        $template = $service->documentTemplate()->sole();
        $this->assertSame($admin->id, $template->uploaded_by);
        $this->assertSame($file->getClientOriginalName(), $template->original_filename);
        $this->assertSame($file->getSize(), $template->file_size);
        $this->assertNotNull($template->uploaded_at);
        $this->assertMatchesRegularExpression('#^service-document-templates/'.$service->id.'/[A-Za-z0-9]{40}\.'.$format.'$#', $template->stored_path);
        Storage::disk('local')->assertExists($template->stored_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->get('/admin/services')->assertSee('Official File')->assertSee(strtoupper($format))->assertDontSee($template->stored_path, false);
        $this->get(route('admin.services.edit', $service))->assertSee($template->original_filename)
            ->assertSee('Download PDF')->assertSee('Replace Template')->assertSee('Remove Template')
            ->assertDontSee($template->stored_path, false);
        $response = $this->get(route('admin.services.template.download', $service))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame(file_get_contents($file->getRealPath()), $response->streamedContent());
        $this->assertArrayNotHasKey('stored_path', $template->toArray());
    }

    public function test_template_is_optional_for_new_and_existing_services(): void
    {
        $this->actingAs($this->admin())->post('/admin/services', ['name' => 'No template'])->assertSessionHas('status');
        $service = Service::sole();
        $this->assertDatabaseCount('service_document_templates', 0);
        $this->get(route('admin.services.edit', $service))->assertSee('No template uploaded')->assertSee('Resident submits');
        $this->put(route('admin.services.update', $service), ['name' => 'Still valid'])->assertSessionHas('status');
        $this->get(route('admin.services.template.download', $service))->assertNotFound();
        $this->get('/admin/services/999/template')->assertNotFound();
    }

    public static function invalidFiles(): array
    {
        return array_map(fn ($kind) => [$kind], ['doc', 'zip', 'svg', 'php', 'spoof-pdf', 'fake-docx', 'arbitrary-zip', 'bad-xml', 'macro', 'traversal', 'entity', 'utf16-entity', 'oversized', 'format-mismatch']);
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_templates_are_rejected_without_files_or_partial_service(string $kind): void
    {
        $file = match ($kind) {
            'doc', 'zip', 'svg', 'php' => UploadedFile::fake()->createWithContent('evil.'.$kind, '<?php echo "bad";'),
            'spoof-pdf' => UploadedFile::fake()->createWithContent('evil.pdf', '<?php echo "bad";'),
            'fake-docx' => UploadedFile::fake()->createWithContent('fake.docx', 'plain text'),
            'oversized' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
            'format-mismatch' => $this->pdf('renamed.docx'),
            default => $this->docx($kind),
        };
        $this->actingAs($this->admin())->from('/admin/services/create')->post('/admin/services', ['name' => 'Invalid', 'official_template' => $file])
            ->assertRedirect('/admin/services/create')->assertSessionHasErrors('official_template');
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('service_document_templates', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function protectedActions(): array
    {
        return [['GET'], ['DELETE']];
    }

    #[DataProvider('protectedActions')]
    public function test_template_actions_require_authenticated_active_verified_admin(string $method): void
    {
        $service = $this->createTemplate();
        $url = '/admin/services/'.$service->id.'/template';
        $this->app['auth']->forgetGuards();
        $this->call($method, $url)->assertRedirect(route('login'));
        $resident = $this->resident();
        $this->actingAs($resident)->call($method, $url)->assertForbidden();
        $admin = $this->admin();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->call($method, $url)->assertRedirect(route('verification.notice'));
        $admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($admin)->call($method, $url)->assertRedirect(route('login'));
        $this->assertDatabaseCount('service_document_templates', 1);
        Storage::disk('local')->assertExists($service->documentTemplate->stored_path);
    }

    public function test_template_is_not_exposed_to_resident_and_download_paths_are_scoped(): void
    {
        $service = $this->createTemplate();
        $template = $service->documentTemplate;
        $other = Service::create(['name' => 'Other']);
        $this->get('/admin/services/'.$other->id.'/template')->assertNotFound();
        $template->update(['stored_path' => '../profile-pictures/secret.png']);
        $this->get('/admin/services/'.$service->id.'/template')->assertNotFound();
        $this->actingAs($this->resident())->get('/resident/reservations/create')
            ->assertSee('Clearance')->assertDontSee('Official Document Template')->assertDontSee($template->original_filename);
    }

    public function test_replace_removes_only_old_master_and_preserves_all_business_records(): void
    {
        $service = $this->createTemplate();
        $original = $service->documentTemplate;
        $oldPath = $original->stored_path;
        $records = $this->businessRecords($service);
        $this->put('/admin/services/'.$service->id, ['name' => $service->name, 'official_template' => $this->pdf('Replacement.pdf')])->assertSessionHas('status');
        $current = $service->fresh()->documentTemplate;
        $this->assertDatabaseCount('service_document_templates', 1);
        $this->assertNotSame($oldPath, $current->stored_path);
        $this->assertSame('PDF', $current->format());
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($current->stored_path);
        $this->assertBusinessRecords($records);
        $this->delete('/admin/services/'.$service->id.'/template', ['confirm_removal' => 1,
            'template_id' => $original->id, 'template_revision' => $original->revision()])->assertSessionHasErrors('template_id');
        Storage::disk('local')->assertExists($current->stored_path);
    }

    public function test_remove_requires_confirmation_and_preserves_service_and_business_records(): void
    {
        $service = $this->createTemplate();
        $template = $service->documentTemplate;
        $records = $this->businessRecords($service);
        $url = '/admin/services/'.$service->id.'/template';
        $data = ['template_id' => $template->id, 'template_revision' => $template->revision()];
        $this->delete($url, $data)->assertSessionHasErrors('confirm_removal');
        $this->delete($url, [...$data, 'confirm_removal' => 1])->assertRedirect(route('admin.services.edit', $service))->assertSessionHas('status');
        $this->assertDatabaseCount('service_document_templates', 0);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'name' => 'Clearance']);
        Storage::disk('local')->assertMissing($template->stored_path);
        $this->assertBusinessRecords($records);
        $this->get($url)->assertNotFound();
    }

    public static function writes(): array
    {
        return [['create'], ['replace']];
    }

    public static function staleRemovalSubmissions(): array
    {
        return [['old_id'], ['stale_revision'], ['missing_id'], ['missing_revision']];
    }

    #[DataProvider('staleRemovalSubmissions')]
    public function test_removal_requires_both_current_id_and_revision_and_preserves_records(string $case): void
    {
        $service = $this->createTemplate();
        $original = $service->documentTemplate;
        $records = $this->businessRecords($service);
        $url = route('admin.services.template.destroy', $service);

        if ($case === 'old_id') {
            $this->delete($url, ['confirm_removal' => 1, 'template_id' => $original->id,
                'template_revision' => $original->revision()])->assertSessionHas('status');
        }
        $this->put(route('admin.services.update', $service), ['name' => $service->name,
            'official_template' => $this->pdf('Current-replacement.pdf')])->assertSessionHas('status');
        $current = $service->fresh()->documentTemplate;
        $this->assertNotSame($original->revision(), $current->revision());
        if ($case === 'old_id') {
            $this->assertNotSame($original->id, $current->id);
        } else {
            // Replacement can retain the row ID; the revision must still be checked.
            $this->assertSame($original->id, $current->id);
        }

        $data = ['confirm_removal' => 1, 'template_id' => $current->id, 'template_revision' => $current->revision()];
        $error = 'template_id';
        if ($case === 'old_id') {
            $data['template_id'] = $original->id;
        } elseif ($case === 'stale_revision') {
            $data['template_revision'] = $original->revision();
        } elseif ($case === 'missing_id') {
            unset($data['template_id']);
        } else {
            unset($data['template_revision']);
            $error = 'template_revision';
        }
        $serviceBefore = $service->fresh()->getAttributes();
        $templateBefore = $current->getAttributes();
        $filesBefore = Storage::disk('local')->allFiles();
        $fileBefore = Storage::disk('local')->get($current->stored_path);

        $response = $this->from(route('admin.services.edit', $service))->delete($url, $data)
            ->assertRedirect(route('admin.services.edit', $service))->assertSessionHasErrors($error);
        if (in_array($case, ['old_id', 'stale_revision'], true)) {
            $response->assertSessionHasErrors(['template_id' => 'The template has changed. Review the current template before confirming removal.']);
        }
        $this->assertDatabaseCount('service_document_templates', 1);
        $this->assertSame($templateBefore, $current->fresh()->getAttributes());
        $this->assertSame($serviceBefore, $service->fresh()->getAttributes());
        $this->assertSame($filesBefore, Storage::disk('local')->allFiles());
        $this->assertSame($fileBefore, Storage::disk('local')->get($current->stored_path));
        $this->assertBusinessRecords($records);

        // A fresh confirmation for the replacement is accepted after the stale attempt.
        $this->delete($url, ['confirm_removal' => 1, 'template_id' => $current->id,
            'template_revision' => $current->revision()])->assertSessionHas('status');
        $this->assertDatabaseCount('service_document_templates', 0);
        Storage::disk('local')->assertMissing($current->stored_path);
        $this->assertSame($serviceBefore, $service->fresh()->getAttributes());
        $this->assertBusinessRecords($records);
    }

    #[DataProvider('writes')]
    public function test_db_failure_cleans_new_file_and_preserves_previous_state(string $action): void
    {
        $service = $action === 'replace' ? $this->createTemplate() : null;
        $old = $service?->documentTemplate;
        $this->actingAs($this->admin());
        $operation = $action === 'replace' ? 'UPDATE' : 'INSERT';
        DB::statement("CREATE TRIGGER fail_template BEFORE $operation ON service_document_templates BEGIN SELECT RAISE(ABORT, 'Simulated failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->call($action === 'replace' ? 'PUT' : 'POST', $service ? '/admin/services/'.$service->id : '/admin/services', ['name' => 'Changed'], [], ['official_template' => $this->pdf()]);
            $this->fail('Expected database failure');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Simulated failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('services', $old ? 1 : 0);
        $this->assertDatabaseCount('service_document_templates', $old ? 1 : 0);
        $this->assertSame($old ? [$old->stored_path] : [], Storage::disk('local')->allFiles());
        if ($old) {
            $this->assertSame($old->getAttributes(), $old->fresh()->getAttributes());
            $this->assertSame('Clearance', $service->fresh()->name);
        }
    }

    #[DataProvider('writes')]
    public function test_storage_failure_rolls_back_service_and_preserves_current_template(string $action): void
    {
        $service = $action === 'replace' ? $this->createTemplate() : null;
        $old = $service?->documentTemplate;
        $disk = Storage::disk('local');
        $proxy = \Mockery::mock($disk)->makePartial();
        $proxy->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        $this->actingAs($this->admin())->call($action === 'replace' ? 'PUT' : 'POST', $service ? '/admin/services/'.$service->id : '/admin/services', ['name' => 'Changed'], [], ['official_template' => $this->pdf()])
            ->assertSessionHasErrors('official_template');
        $this->assertDatabaseCount('services', $old ? 1 : 0);
        $this->assertDatabaseCount('service_document_templates', $old ? 1 : 0);
        $this->assertSame($old ? [$old->stored_path] : [], $disk->allFiles());
        if ($old) {
            $this->assertSame('Clearance', $service->fresh()->name);
        }
    }

    private function businessRecords(Service $service): array
    {
        $requirement = $service->serviceRequirements()->create(['name' => 'Valid ID']);
        $schedule = Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 5]);
        $reservation = $this->resident()->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => ReservationStatus::Approved]);
        $path = 'reservation-attachments/'.$reservation->id.'/'.str_repeat('a', 40).'.pdf';
        Storage::disk('local')->put($path, 'Existing Resident evidence');
        $attachment = $reservation->attachments()->create(['service_requirement_id' => $requirement->id, 'original_filename' => 'ID.pdf', 'stored_path' => $path, 'mime_type' => 'application/pdf', 'file_size' => 26]);
        $qr = $reservation->qrTicket()->create(['ticket_code' => str_repeat('b', 40), 'qr_payload' => '11111111-1111-4111-8111-111111111111', 'generated_at' => now()]);

        return array_map(fn ($record) => $record->fresh(), [$requirement, $reservation, $attachment, $qr]);
    }

    private function assertBusinessRecords(array $records): void
    {
        foreach ($records as $record) {
            $this->assertSame($record->getAttributes(), $record->fresh()->getAttributes());
        }
        Storage::disk('local')->assertExists($records[2]->stored_path);
    }

    private function createTemplate(): Service
    {
        $this->actingAs($this->admin())->post('/admin/services', ['name' => 'Clearance', 'official_template' => $this->pdf()])->assertSessionHas('status');

        return Service::sole();
    }

    private function admin(): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();

        return $user;
    }

    private function resident(): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => UserRole::Resident, 'is_active' => true])->save();

        return $user;
    }

    private function pdf(string $name = 'Calayo-master.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, CertificatePdf::bytes());
    }

    private function docx(string $kind = 'valid'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'calayo-docx-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Barangay Calayo official template</w:t></w:r></w:p></w:body></w:document>');
        match ($kind) {
            'arbitrary-zip' => $zip->deleteName('word/document.xml'),
            'bad-xml' => $zip->addFromString('word/document.xml', '<broken>'),
            'macro' => $zip->addFromString('word/vbaProject.bin', 'macro'),
            'traversal' => $zip->addFromString('../danger.php', 'bad'),
            'entity' => $zip->addFromString('word/document.xml', '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///secret">]><x>&x;</x>'),
            'utf16-entity' => $zip->addFromString('word/document.xml', "\xFF\xFE".mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE w:document [<!ENTITY secret SYSTEM "file:///secret">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&secret;</w:t></w:r></w:p></w:body></w:document>', 'UTF-16LE', 'UTF-8')),
            default => null,
        };
        $zip->close();
        try {
            return UploadedFile::fake()->createWithContent('Calayo-official-master-template-with-long-original-filename.docx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
