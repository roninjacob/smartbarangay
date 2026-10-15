<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DocumentField;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class DocumentDesignerTest extends TestCase
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
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $service = Service::create(['name' => 'Generic Official Document']);
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('a', 40).'.pdf';
        $bytes = CertificatePdf::twoPages();
        Storage::disk('local')->put($path, $bytes);
        $pdf = $service->documentTemplate()->create(['original_filename' => 'Official.pdf', 'stored_path' => $path, 'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'uploaded_at' => now()]);
        $template = $service->dynamicDocumentTemplate()->make();
        $template->forceFill(['service_document_template_id' => $pdf->id, 'source_revision' => $pdf->revision(), 'source_content_hash' => hash('sha256', $bytes), 'source_filename' => 'Official.pdf', 'version' => 1, 'is_active' => true, 'created_by' => $admin->id])->save();
        $field = $template->fields()->create(['field_name' => 'purpose', 'label' => 'Purpose', 'source' => 'custom', 'field_type' => 'text', 'is_required' => true, 'sort_order' => 7, 'validation_rules' => ['max_length' => 100]]);
        $this->actingAs($admin);

        return [$service, $template, $field, $admin];
    }

    private function url(Service $service): string
    {
        return '/admin/services/'.$service->id.'/document-layout';
    }

    private function position(DocumentField $field): array
    {
        return ['field_id' => $field->id, 'system_name' => null, 'page_number' => 1, 'x_position' => 72.5, 'y_position' => 110.25,
            'width' => 180, 'height' => 30, 'font_size' => 12, 'alignment' => 'center', 'text_behavior' => 'wrap'];
    }

    private function saveLayout(Service $service, array $positions, array $extra = [])
    {
        $template = $service->dynamicDocumentTemplate()->sole();

        return $this->putJson($this->url($service), ['version' => $template->version, 'pdf_revision' => $template->source_revision, 'placements' => $positions, ...$extra]);
    }

    public static function endpoints(): array
    {
        return [['get', ''], ['put', ''], ['get', '/pdf']];
    }

    #[DataProvider('endpoints')]
    public function test_every_endpoint_requires_active_verified_admin(string $method, string $suffix): void
    {
        [$service,$template,$field,$admin] = $this->fixture();
        $url = $this->url($service).$suffix;
        auth()->logout();
        $this->{$method}($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]))->{$method}($url)->assertForbidden();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->{$method}($url)->assertRedirect(route('verification.notice'));
        $admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($admin)->{$method}($url)->assertRedirect(route('login'));
        $this->assertDatabaseCount('document_field_positions', 0);
    }

    public function test_admin_sees_private_designer_and_system_and_custom_toolbox_without_manual_coordinates(): void
    {
        [$service,$template,$field] = $this->fixture();
        $this->get($this->url($service))->assertOk()->assertSee('Save Layout')->assertSee('Resident Name')->assertSee('Purpose')->assertSee('Preview with Sample Data')->assertSee('data-designer-canvas', false)->assertDontSee($service->documentTemplate->stored_path, false);
        $this->get('/admin/services/'.$service->id.'/document-fields/'.$field->id.'/edit')->assertOk()->assertDontSee('X position')->assertDontSee('Y position')->assertSee('Design Document Layout');
        $this->get('/admin/services/'.$service->id.'/document-configuration')->assertOk()->assertSee('Design Document Layout');
        $this->get($this->url($service).'/pdf?revision='.$template->source_revision)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->get($this->url($service).'/pdf?revision=wrong')->assertNotFound();
    }

    public function test_layout_save_reuses_positions_and_preserves_fields_original_pdf_and_business_records(): void
    {
        [$service,$template,$field] = $this->fixture();
        $before = $field->fresh()->getAttributes();
        $pdf = $service->documentTemplate->fresh()->getAttributes();
        $bytes = Storage::disk('local')->get($pdf['stored_path']);
        $records = collect(['services', 'reservations', 'qr_tickets', 'reservation_status_histories', 'reservation_documents', 'reservation_attachments'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
        $this->saveLayout($service, [$this->position($field)])->assertOk()->assertJsonPath('version', 2);
        $position = $field->positions()->sole();
        $this->assertSame('72.500', $position->x_position);
        $this->assertSame('wrap', $position->text_behavior);
        $this->saveLayout($service, [[...$this->position($field), 'page_number' => 2], $this->position($field)])->assertOk()->assertJsonPath('version', 3);
        $this->assertCount(2, $field->positions()->get());
        $this->assertSame($before, $field->fresh()->getAttributes());
        $this->assertSame($pdf, $service->documentTemplate()->sole()->getAttributes());
        $this->assertSame($bytes, Storage::disk('local')->get($pdf['stored_path']));
        foreach ($records as $table => $value) {
            $this->assertSame($value, DB::table($table)->get()->toJson());
        }
        $this->saveLayout($service, [])->assertOk();
        $this->assertDatabaseCount('document_field_positions', 0);
        $this->assertDatabaseCount('document_fields', 1);
    }

    public function test_standard_system_fields_are_added_only_when_placed_and_existing_definitions_stay_unchanged(): void
    {
        [$service,$template,$field] = $this->fixture();
        $before = $field->fresh()->getAttributes();
        $position = [...$this->position($field), 'field_id' => null, 'system_name' => 'resident_name'];
        $this->saveLayout($service, [$position, [...$position, 'y_position' => 160]])->assertOk();
        $this->assertDatabaseCount('document_fields', 2);
        $system = $template->fields()->where('field_name', 'resident_name')->sole();
        $this->assertSame('system', $system->source);
        $this->assertSame('text', $system->field_type->value);
        $this->assertCount(2, $system->positions()->get());
        $this->saveLayout($service, [$position])->assertOk();
        $this->assertDatabaseCount('document_fields', 2);
        $this->assertSame($before, $field->fresh()->getAttributes());
    }

    public function test_foreign_field_causes_atomic_rejection_and_preserves_existing_placement(): void
    {
        [$first,$template,$field] = $this->fixture();
        $this->saveLayout($first, [$this->position($field)])->assertOk();
        $before = $field->positions()->sole()->getAttributes();
        [$second,$other,$foreign] = $this->fixture();
        $this->saveLayout($first, [[...$this->position($field), 'field_id' => null, 'system_name' => 'resident_name'], $this->position($foreign)])->assertUnprocessable()->assertJsonValidationErrors('layout');
        $this->assertSame($before, $field->positions()->sole()->getAttributes());
        $this->assertSame(2, $template->fresh()->version);
        $this->assertSame(1, $template->fields()->count());
    }

    public static function invalidPlacements(): array
    {
        return [[['page_number' => 0]], [['x_position' => -1]], [['width' => 0]], [['height' => 20001]], [['font_size' => 73]], [['alignment' => 'bad']], [['text_behavior' => 'script']], [['system_name' => 'unknown', 'field_id' => null]], [['field_id' => null, 'system_name' => null]], [['system_name' => 'resident_name']], [['stored_path' => 'public/evil.pdf']]];
    }

    #[DataProvider('invalidPlacements')]
    public function test_invalid_layout_is_rejected_without_mutation(array $extra): void
    {
        [$service,$template,$field] = $this->fixture();
        $this->saveLayout($service, [[...$this->position($field), ...$extra]])->assertUnprocessable();
        $this->assertDatabaseCount('document_field_positions', 0);
        $this->assertSame(1, $template->fresh()->version);
    }

    public function test_stale_version_and_replaced_or_missing_pdf_are_rejected(): void
    {
        [$service,$template,$field] = $this->fixture();
        $this->saveLayout($service, [$this->position($field)])->assertOk();
        $before = $field->positions()->sole()->getAttributes();
        $this->saveLayout($service, [], ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('template');
        $this->saveLayout($service, [], ['pdf_revision' => str_repeat('f', 64)])->assertUnprocessable()->assertJsonValidationErrors('layout');
        $pdf = $service->documentTemplate;
        $new = 'service-document-templates/'.$service->id.'/'.str_repeat('b', 40).'.pdf';
        Storage::disk('local')->put($new, CertificatePdf::bytes('Replacement'));
        $pdf->update(['stored_path' => $new]);
        $this->saveLayout($service, [])->assertUnprocessable();
        $this->get($this->url($service))->assertOk()->assertSee('official PDF has changed')->assertDontSee('data-designer-canvas', false);
        $this->get($this->url($service).'/pdf?revision='.$template->source_revision)->assertNotFound();
        $this->assertSame($before, $field->positions()->sole()->getAttributes());
        $this->assertSame(2, $template->fresh()->version);
        $template->forceFill(['source_revision' => $pdf->revision()])->save();
        Storage::disk('local')->delete($new);
        $this->saveLayout($service, [])->assertUnprocessable();
        $this->get($this->url($service).'/pdf?revision='.$template->source_revision)->assertNotFound();
    }

    public function test_layout_save_requires_csrf(): void
    {
        [$service,$template,$field] = $this->fixture();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->saveLayout($service, [$this->position($field)])->assertStatus(419);
        $this->assertDatabaseCount('document_field_positions', 0);
    }
}
