<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus as Status;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\ReservationDocument;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\ServiceDocumentTemplate;
use App\Models\User;
use App\Rules\OfficialDocumentTemplate;
use App\Services\DocumentMergeFields;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class ReservationDocumentTest extends TestCase
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
        ReservationDocument::flushEventListeners();
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public static function statuses(): array
    {
        return array_map(fn ($status) => [$status], Status::cases());
    }

    #[DataProvider('statuses')]
    public function test_generation_eligibility_is_enforced_server_side(Status $status): void
    {
        $reservation = $this->reservation($status);
        $this->master($reservation->service);
        $response = $this->actingAs($this->user())->post($this->url($reservation));
        if ($status->canPrepareDocument()) {
            $response->assertSessionHasNoErrors()->assertRedirect(route('admin.reservations.show', $reservation));
            $this->assertDatabaseCount('reservation_documents', 1);
            $this->get($this->url($reservation))->assertOk();
        } else {
            $response->assertSessionHasErrors('document');
            $this->assertDatabaseCount('reservation_documents', 0);
        }
        $this->assertSame($status, $reservation->fresh()->status);
    }

    public function test_all_supported_fields_plain_text_formatting_private_storage_and_unchanged_business_records(): void
    {
        $this->travelTo(now()->setTimezone('Asia/Manila')->setDate(2026, 10, 8)->setTime(0, 30));
        $reservation = $this->reservation();
        $reservation->user->update(['name' => 'Ana & <Calayo>']);
        $master = $this->master($reservation->service, implode(' | ', array_map(fn ($field) => '${'.$field.'}', array_keys(DocumentMergeFields::LABELS))));
        $disk = Storage::disk('local');
        $masterBytes = $disk->get($master->stored_path);
        $requirement = $reservation->service->serviceRequirements()->create(['name' => 'Valid ID']);
        $attachmentPath = 'reservation-attachments/'.$reservation->id.'/'.Str::random(40).'.pdf';
        $disk->put($attachmentPath, 'Resident evidence');
        $attachment = $reservation->attachments()->create(['service_requirement_id' => $requirement->id, 'original_filename' => 'id.pdf', 'stored_path' => $attachmentPath, 'mime_type' => 'application/pdf', 'file_size' => 17]);
        $ticket = $reservation->qrTicket()->create(['ticket_code' => Str::random(40), 'qr_payload' => (string) Str::uuid(), 'generated_at' => now()]);
        $history = $reservation->statusHistories()->create(['from_status' => Status::UnderReview, 'to_status' => Status::Approved, 'changed_at' => now()]);
        $records = [$reservation->fresh(), $reservation->service->fresh(), $reservation->schedule->fresh(), $reservation->user->fresh(), $requirement->fresh(), $attachment->fresh(), $ticket->fresh(), $history->fresh()];
        $admin = $this->user();
        $this->actingAs($admin)->post($this->url($reservation), ['stored_path' => '../../secret', 'template_id' => 999, 'generated_by' => 999])->assertSessionHasNoErrors();
        $document = $reservation->document()->sole();
        $this->assertSame($admin->id, $document->generated_by);
        $this->assertSame($master->revision(), $document->template_revision);
        $this->assertSame($master->original_filename, $document->template_filename);
        $this->assertMatchesRegularExpression('#^reservation-documents/'.$reservation->id.'/[A-Za-z0-9]{40}\.docx$#D', $document->stored_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertArrayNotHasKey('stored_path', $document->toArray());
        $this->assertSame($masterBytes, $disk->get($master->stored_path));
        $merged = $this->parts($disk->path($document->stored_path));
        $original = $this->parts($disk->path($master->stored_path));
        $xml = $merged['word/document.xml'];
        foreach (app(DocumentMergeFields::class)->values($reservation->fresh()->load(['user', 'service', 'schedule'])) as $value) {
            $this->assertStringContainsString(htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $xml);
        }
        $this->assertStringNotContainsString('${', implode('', $merged));
        $this->assertStringContainsString('Ana &amp; &lt;Calayo&gt;', $xml);
        $this->assertStringContainsString('w:tbl', $xml);
        $this->assertStringContainsString('w:pgMar', $xml);
        $this->assertStringContainsString('Georgia', $merged['word/styles.xml']);
        $this->assertNotEmpty(array_filter(array_keys($original), fn ($name) => str_contains($name, '/media/')));
        foreach ($original as $name => $contents) {
            if (! in_array($name, ['word/document.xml', 'word/header1.xml', 'word/footer1.xml', 'word/settings.xml', '[Content_Types].xml', 'word/_rels/document.xml.rels', 'word/_rels/header1.xml.rels', 'word/_rels/footer1.xml.rels'], true)) {
                $this->assertSame($contents, $merged[$name], $name.' must be preserved');
            }
        }
        $this->assertStringContainsString('Barangay Calayo', $merged['word/header1.xml']);
        $this->assertStringContainsString('Request #'.$reservation->id, $merged['word/footer1.xml']);
        foreach ($records as $record) {
            $this->assertSame($record->getAttributes(), $record->fresh()->getAttributes());
        }
        $disk->assertExists($attachmentPath);
        $this->assertSame([], $disk->allFiles('document-generation'));
        $page = $this->get(route('admin.reservations.show', $reservation))->assertOk()->assertSee('Document status: Prepared')->assertSee('Download Ready-to-Print DOCX')->assertDontSee($document->stored_path, false);
        $this->assertStringNotContainsString($master->stored_path, $page->getContent());
        $response = $this->get($this->url($reservation))->assertOk()->assertHeader('Content-Type', OfficialDocumentTemplate::DOCX_MIME)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('-Request-'.$reservation->id.'.docx', $response->headers->get('Content-Disposition'));
        $this->assertSame($disk->get($document->stored_path), $response->streamedContent());
        $this->actingAs($reservation->user)->get(route('resident.reservations.show', $reservation))->assertOk()->assertDontSee('Official Document Preparation')->assertDontSee($document->original_filename)->assertDontSee($master->original_filename)->assertDontSee($document->stored_path, false);
    }

    public static function invalidMasters(): array
    {
        return array_map(fn ($kind) => [$kind], ['none', 'pdf', 'missing-file', 'foreign-path', 'traversal', 'corrupt', 'unsafe-xml', 'unsupported', 'missing-address', 'missing-purpose', 'reserved-value', 'incomplete', 'unsupported-part', 'unsupported-link']);
    }

    #[DataProvider('invalidMasters')]
    public function test_invalid_generation_has_clear_errors_and_no_output(string $kind): void
    {
        $reservation = $this->reservation();
        $master = $kind === 'none' ? null : $this->master($reservation->service, match ($kind) {
            'unsupported' => '${password} ${unknown_field}', 'missing-address' => '${resident_address}',
            'missing-purpose' => '${purpose}', 'incomplete' => '${resident_name', default => '${resident_name}',
        });
        $disk = Storage::disk('local');
        if ($kind === 'pdf') {
            $master->update(['mime_type' => 'application/pdf']);
        } elseif ($kind === 'missing-file') {
            $disk->delete($master->stored_path);
        } elseif (in_array($kind, ['foreign-path', 'traversal'])) {
            $master->update(['stored_path' => $kind === 'traversal' ? '../../secret.docx' : 'service-document-templates/999/'.Str::random(40).'.docx']);
        } elseif ($kind === 'corrupt') {
            $disk->put($master->stored_path, 'not a DOCX');
        } elseif ($kind === 'missing-address') {
            $reservation->user->update(['address' => null]);
        } elseif ($kind === 'missing-purpose') {
            $reservation->update(['purpose' => null]);
        } elseif ($kind === 'reserved-value') {
            $reservation->user->update(['name' => '${service_name}']);
        } elseif (in_array($kind, ['unsafe-xml', 'unsupported-part', 'unsupported-link'])) {
            $zip = new ZipArchive;
            $zip->open($disk->path($master->stored_path));
            if ($kind === 'unsupported-link') {
                $zip->addFromString('word/_rels/custom.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="test" Target="${unknown_field}"/></Relationships>');
            } else {
                $zip->addFromString('word/footnotes.xml', $kind === 'unsafe-xml' ? '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///secret">]><x>&x;</x>' : '<w:footnotes xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:t>${resident_name}</w:t></w:footnotes>');
            }
            // The adversarial fixture is also rewritten through a ZIP on Windows.
            // A transient scanner lock must not prevent exercising application validation.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $closed = @$zip->close();
                if ($closed) {
                    break;
                }
                usleep(100000 * ($attempt + 1));
            }
            $this->assertTrue($closed);
        }
        $before = $disk->allFiles();
        $response = $this->actingAs($this->user())->post($this->url($reservation))->assertSessionHasErrors('document');
        if ($kind === 'unsupported') {
            $response->assertSessionHasErrors(['document' => 'This template contains unsupported merge fields: ${password}, ${unknown_field}']);
        }
        $this->assertDatabaseCount('reservation_documents', 0);
        $this->assertSame($before, $disk->allFiles());
        $this->assertSame(Status::Approved, $reservation->fresh()->status);
    }

    public function test_missing_values_not_used_by_template_do_not_block_preparation(): void
    {
        $reservation = $this->reservation();
        $reservation->user->update(['address' => null, 'contact_number' => null]);
        $reservation->update(['purpose' => null]);
        $this->master($reservation->service);
        $this->actingAs($this->user())->post($this->url($reservation))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('reservation_documents', 1);
    }

    public static function endpoints(): array
    {
        return [['GET'], ['POST']];
    }

    #[DataProvider('endpoints')]
    public function test_access_requires_authenticated_active_verified_admin(string $method): void
    {
        $reservation = $this->reservation();
        $this->master($reservation->service);
        $this->call($method, $this->url($reservation))->assertRedirect(route('login'));
        $this->actingAs($reservation->user)->call($method, $this->url($reservation))->assertForbidden();
        $other = $this->user(UserRole::Resident);
        $this->actingAs($other)->call($method, $this->url($reservation))->assertForbidden();
        $admin = $this->user();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->call($method, $this->url($reservation))->assertRedirect(route('verification.notice'));
        $admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($admin)->call($method, $this->url($reservation))->assertRedirect(route('login'));
        $this->assertDatabaseCount('reservation_documents', 0);
    }

    public function test_csrf_protection_and_nonexistent_reservations(): void
    {
        $reservation = $this->reservation();
        $this->actingAs($this->user())->get('/admin/reservations/999/document')->assertNotFound();
        $this->post('/admin/reservations/999/document')->assertNotFound();
        $this->app['env'] = 'local';
        $this->post($this->url($reservation))->assertStatus(419);
        $this->assertDatabaseCount('reservation_documents', 0);
    }

    public function test_regeneration_uses_current_template_preserves_old_until_commit_and_completed_history(): void
    {
        $reservation = $this->reservation(Status::ReadyForPickup);
        $master = $this->master($reservation->service, 'OLD ${resident_name}');
        $this->actingAs($this->user())->post($this->url($reservation))->assertSessionHasNoErrors();
        $old = $reservation->document()->sole();
        $disk = Storage::disk('local');
        $oldBytes = $disk->get($old->stored_path);
        $oldMetadata = $old->getAttributes();
        $this->put(route('admin.services.update', $reservation->service), ['name' => $reservation->service->name, 'official_template' => $this->docx('NEW ${resident_name}')])->assertSessionHasNoErrors();
        $current = $master->fresh();
        $this->assertSame($master->id, $current->id);
        $this->assertNotSame($master->revision(), $current->revision());
        $this->assertSame($oldMetadata, $old->fresh()->getAttributes());
        $this->assertSame($oldBytes, $disk->get($old->stored_path));
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Official template has changed')->assertSee('Regenerate Document');
        ReservationDocument::saving(function () use ($disk, $old) {
            $disk->assertExists($old->stored_path);
        });
        $this->post($this->url($reservation), ['template_id' => 999, 'template_revision' => $master->revision(), 'stored_path' => $master->stored_path])->assertSessionHasNoErrors();
        $new = $old->fresh();
        $this->assertSame($old->id, $new->id);
        $this->assertNotSame($old->stored_path, $new->stored_path);
        $this->assertSame($current->revision(), $new->template_revision);
        $disk->assertMissing($old->stored_path);
        $this->assertStringContainsString('NEW', $this->parts($disk->path($new->stored_path))['word/document.xml']);
        $this->assertDatabaseCount('reservation_documents', 1);
        $this->get(route('admin.reservations.show', $reservation))->assertDontSee('Official template has changed');
        $reservation->update(['status' => Status::Completed]);
        $historical = $new->getAttributes();
        $this->get($this->url($reservation))->assertOk();
        $this->get(route('admin.reservations.show', $reservation))->assertSee('historical record')->assertDontSee('Regenerate Document');
        $this->post($this->url($reservation))->assertSessionHasErrors('document');
        $this->assertSame($historical, $new->fresh()->getAttributes());
        $disk->assertExists($new->stored_path);
    }

    public static function failures(): array
    {
        return [['initial-db'], ['regeneration-db'], ['regeneration-template'], ['regeneration-storage']];
    }

    #[DataProvider('failures')]
    public function test_failed_generation_cleans_new_files_and_preserves_previous_document(string $kind): void
    {
        $reservation = $this->reservation();
        $master = $this->master($reservation->service);
        $this->actingAs($this->user());
        if ($kind !== 'initial-db') {
            $this->post($this->url($reservation))->assertSessionHasNoErrors();
        }
        $old = $reservation->document()->first();
        $disk = Storage::disk('local');
        $before = $disk->allFiles();
        $metadata = $old?->getAttributes();
        $bytes = $old ? $disk->get($old->stored_path) : null;
        if (str_ends_with($kind, '-db')) {
            DB::statement('CREATE TRIGGER fail_prepared_document BEFORE '.($old ? 'UPDATE' : 'INSERT')." ON reservation_documents BEGIN SELECT RAISE(ABORT, 'simulated metadata failure'); END;");
            $this->post($this->url($reservation))->assertSessionHasErrors('document');
        } elseif ($kind === 'regeneration-template') {
            $disk->put($master->stored_path, 'corrupt');
            $this->post($this->url($reservation))->assertSessionHasErrors('document');
        } else {
            $proxy = \Mockery::mock($disk)->makePartial();
            $proxy->shouldReceive('putFileAs')->once()->andReturn(false);
            Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
            $this->post($this->url($reservation))->assertSessionHasErrors('document');
        }
        $this->assertSame($before, $disk->allFiles());
        $this->assertDatabaseCount('reservation_documents', $old ? 1 : 0);
        if ($old) {
            $this->assertSame($metadata, $old->fresh()->getAttributes());
            $this->assertSame($bytes, $disk->get($old->stored_path));
        }
        $this->assertSame(Status::Approved, $reservation->fresh()->status);
    }

    public function test_master_removal_and_admin_deletion_preserve_generated_document(): void
    {
        $reservation = $this->reservation();
        $master = $this->master($reservation->service);
        $admin = $this->user();
        $this->actingAs($admin)->post($this->url($reservation))->assertSessionHasNoErrors();
        $document = $reservation->document()->sole();
        $bytes = Storage::disk('local')->get($document->stored_path);
        $this->delete(route('admin.services.template.destroy', $reservation->service), ['confirm_removal' => 1, 'template_id' => $master->id, 'template_revision' => $master->revision()])->assertSessionHasNoErrors();
        $this->assertNull($document->fresh()->service_document_template_id);
        $this->assertSame($master->revision(), $document->fresh()->template_revision);
        $this->get($this->url($reservation))->assertOk();
        $this->assertSame($bytes, Storage::disk('local')->get($document->stored_path));
        $admin->delete();
        $this->assertNull($document->fresh()->generated_by);
        $this->actingAs($this->user())->get($this->url($reservation))->assertOk();
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Former Admin account')->assertSee('No official document template')->assertSee('Download Ready-to-Print DOCX');
        $this->assertSame(Status::Approved, $reservation->fresh()->status);
    }

    public static function badDownloads(): array
    {
        return [['absent'], ['missing'], ['corrupt'], ['foreign'], ['traversal']];
    }

    #[DataProvider('badDownloads')]
    public function test_download_fails_safely_for_missing_or_invalid_generated_file(string $kind): void
    {
        $reservation = $this->reservation();
        $this->master($reservation->service);
        $this->actingAs($this->user());
        if ($kind !== 'absent') {
            $this->post($this->url($reservation))->assertSessionHasNoErrors();
            $document = $reservation->document()->sole();
            match ($kind) {
                'missing' => Storage::disk('local')->delete($document->stored_path),
                'corrupt' => Storage::disk('local')->put($document->stored_path, 'not DOCX'),
                'foreign' => $document->update(['stored_path' => 'reservation-documents/999/'.Str::random(40).'.docx']),
                'traversal' => $document->update(['stored_path' => '../../secret.docx']),
            };
        }
        $this->get($this->url($reservation))->assertNotFound();
    }

    public function test_no_template_pdf_docx_preview_and_merge_help_states(): void
    {
        $reservation = $this->reservation();
        $this->actingAs($this->user())->get(route('admin.reservations.show', $reservation))->assertSee('No official document template')->assertSee('Edit Service')->assertDontSee('Prepare Ready-to-Print Document');
        $master = $this->master($reservation->service);
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Resident data preview')->assertSee('Employment')->assertSee('Prepare Ready-to-Print Document');
        $this->get(route('admin.services.edit', $reservation->service))->assertSee('Supported merge fields')->assertSee('${resident_address}')->assertSee('${purpose}');
        $master->update(['mime_type' => 'application/pdf']);
        $this->get(route('admin.reservations.show', $reservation))->assertSee('DOCX templates only')->assertSee('Download Official Template')->assertDontSee('Prepare Ready-to-Print Document');
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->make(['name' => 'Juan Dela Cruz', 'address' => 'Sitio Calayo', 'contact_number' => '09123456789']);
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }

    private function reservation(Status $status = Status::Approved): Reservation
    {
        $service = Service::create(['name' => 'Barangay Clearance', 'is_active' => true]);
        $schedule = Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 5]);

        return $this->user(UserRole::Resident)->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status, 'purpose' => 'Employment']);
    }

    private function master(Service $service, string $text = '${resident_name}'): ServiceDocumentTemplate
    {
        $file = $this->docx($text);
        $path = $file->storeAs('service-document-templates/'.$service->id, Str::random(40).'.docx', 'local');

        return $service->documentTemplate()->create(['original_filename' => 'Barangay-Calayo-official-document-master-with-a-long-template-name.docx', 'stored_path' => $path, 'mime_type' => OfficialDocumentTemplate::DOCX_MIME, 'file_size' => $file->getSize(), 'uploaded_at' => now()]);
    }

    private function docx(string $text): UploadedFile
    {
        $word = new PhpWord;
        $word->setDefaultFontName('Georgia');
        $section = $word->addSection(['marginTop' => 900]);
        $header = $section->addHeader();
        $header->addText('${barangay_name}');
        $imagePath = tempnam(sys_get_temp_dir(), 'sb-logo-test-');
        $image = imagecreatetruecolor(2, 2);
        imagepng($image, $imagePath);
        imagedestroy($image);
        $header->addImage($imagePath, ['width' => 12, 'height' => 12]);
        $section->addFooter()->addText('${reservation_reference}');
        $section->addText($text);
        $run = $section->addTextRun();
        $run->addText('${resident_');
        $run->addText('name}');
        $table = $section->addTable();
        $table->addRow();
        $table->addCell(3000)->addText('Official document');
        $path = tempnam(sys_get_temp_dir(), 'sb-docx-test-');
        try {
            IOFactory::createWriter($word, 'Word2007')->save($path);

            return UploadedFile::fake()->createWithContent('Calayo-master.docx', file_get_contents($path));
        } finally {
            unlink($path);
            unlink($imagePath);
        }
    }

    private function parts(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CHECKCONS));
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $parts[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
            if (str_ends_with($zip->getNameIndex($i), '.xml')) {
                $xml = new \DOMDocument;
                $this->assertTrue($xml->loadXML($parts[$zip->getNameIndex($i)], LIBXML_NONET));
            }
        }
        $zip->close();

        return $parts;
    }

    private function url(Reservation $reservation): string
    {
        return '/admin/reservations/'.$reservation->id.'/document';
    }
}
