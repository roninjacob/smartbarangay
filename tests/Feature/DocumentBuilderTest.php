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

class DocumentBuilderTest extends TestCase
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

    public static function endpoints(): array
    {
        return [['get', '/document-configuration'], ['post', '/document-configuration'], ['patch', '/document-configuration'],
            ['get', '/document-fields/create'], ['post', '/document-fields'], ['get', '/document-fields/1/edit'],
            ['put', '/document-fields/1'], ['delete', '/document-fields/1']];
    }

    #[DataProvider('endpoints')]
    public function test_all_builder_actions_require_active_verified_admin(string $method, string $suffix): void
    {
        $service = $this->service();
        $this->configure($service);
        $this->add($service)->assertSessionHasNoErrors();
        auth()->logout();
        $uri = '/admin/services/'.$service->id.$suffix;
        $this->{$method}($uri)->assertRedirect(route('login'));
        $this->actingAs($this->user(UserRole::Resident))->{$method}($uri)->assertForbidden();
        $admin = $this->user();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->{$method}($uri)->assertRedirect(route('verification.notice'));
        $admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($admin)->{$method}($uri)->assertRedirect(route('login'));
        $this->assertDatabaseCount('document_fields', 1);
    }

    public function test_multiple_services_get_independent_configurations_and_original_pdf_is_unchanged(): void
    {
        $admin = $this->user();
        $first = $this->service();
        $second = $this->service('Future Barangay Document');
        $before = $this->originalRecords();
        $pdf = $first->documentTemplate;
        $bytes = Storage::disk('local')->get($pdf->stored_path);
        $this->actingAs($admin);
        foreach ([$first, $second] as $service) {
            $this->configure($service, ['created_by' => 999, 'service_id' => 999, 'version' => 999]);
        }
        $config = $first->dynamicDocumentTemplate()->sole();
        $this->assertSame($admin->id, $config->created_by);
        $this->assertSame(1, $config->version);
        $this->assertSame($pdf->id, $config->service_document_template_id);
        $this->assertSame($pdf->revision(), $config->source_revision);
        $this->assertTrue($config->matchesCurrentPdf());
        $this->assertSame($before, $this->originalRecords());
        $this->assertSame($bytes, Storage::disk('local')->get($pdf->stored_path));
        $this->get($this->configUrl($first))->assertOk()->assertSee('No document fields yet')->assertSee('Configuration version')->assertDontSee($pdf->stored_path, false);
        $this->configure($first, [], false)->assertSessionHasErrors('template');
        $this->assertDatabaseCount('document_templates', 2);
    }

    public static function fieldTypes(): array
    {
        return [['text', ['max_length' => 100]], ['number', ['min_value' => 0, 'max_value' => 50]], ['date', []],
            ['dropdown', ['options_text' => "Employment\nMedical Assistance\nScholarship"]]];
    }

    #[DataProvider('fieldTypes')]
    public function test_custom_fields_store_required_typed_validation_and_options(string $type, array $extra): void
    {
        $service = $this->service();
        $this->configure($service);
        $this->add($service, ['field_type' => $type, 'is_required' => 1, ...$extra])->assertSessionHasNoErrors();
        $field = DocumentField::sole();
        $this->assertSame($type, $field->field_type->value);
        $this->assertTrue($field->is_required);
        $this->assertSame(2, $service->dynamicDocumentTemplate()->sole()->version);
        if ($type === 'text') {
            $this->assertSame(['max_length' => 100], $field->validation_rules);
        }
        if ($type === 'number') {
            $this->assertEquals(['min' => 0, 'max' => 50], $field->validation_rules);
        }
        if ($type === 'dropdown') {
            $this->assertSame(['Employment', 'Medical Assistance', 'Scholarship'], $field->options);
        }
        if ($type === 'date') {
            $this->assertNull($field->validation_rules);
        }
    }

    public static function invalidFields(): array
    {
        return [[['field_type' => 'file'], 'field_type'], [['field_name' => 'Invalid Name'], 'field_name'],
            [['source' => 'system', 'field_name' => 'password'], 'field_name'], [['source' => 'custom', 'field_name' => 'resident_name'], 'field_name'],
            [['source' => 'system', 'field_name' => 'date_issued', 'field_type' => 'text'], 'field_name'],
            [['max_length' => 1001], 'max_length'], [['validation_rules' => ['regex' => 'unsafe']], 'validation_rules'],
            [['field_type' => 'dropdown', 'options_text' => "Same\nSame"], 'options.1'],
            [['field_type' => 'dropdown'], 'options'], [['field_type' => 'number', 'min_value' => 10, 'max_value' => 1], 'max_value'],
            [['field_type' => 'text', 'min_value' => 1], 'min_value'], [['has_position' => 1, 'position' => ['width' => -1]], 'position.width']];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_configuration_is_rejected_without_writes(array $bad, string $error): void
    {
        $service = $this->service();
        $this->configure($service);
        $this->add($service, $bad)->assertSessionHasErrors($error);
        $this->assertDatabaseCount('document_fields', 0);
        $this->assertSame(1, $service->dynamicDocumentTemplate()->sole()->version);
    }

    public function test_system_fields_position_edit_delete_and_version_safeguards(): void
    {
        $service = $this->service();
        $this->configure($service);
        $original = $this->originalRecords();
        $data = ['source' => 'system', 'field_name' => 'resident_name', 'label' => 'Resident Name', 'has_position' => 1,
            'position' => ['page_number' => 2, 'x_position' => 40, 'y_position' => 90, 'width' => 250, 'height' => 20,
                'font_size' => 12, 'alignment' => 'center', 'text_behavior' => 'auto_resize']];
        $this->add($service, $data)->assertSessionHasNoErrors();
        $field = DocumentField::sole();
        $this->assertSame(2, $field->positions()->sole()->page_number);
        $this->get('/admin/services/'.$service->id.'/document-fields/'.$field->id.'/edit')->assertOk()->assertSee('Resident Name');
        $this->put('/admin/services/'.$service->id.'/document-fields/'.$field->id, $this->fieldData($service) + [])->assertSessionHasNoErrors();
        $this->assertSame('purpose', $field->fresh()->field_name);
        $this->assertDatabaseCount('document_field_positions', 0);
        $this->delete('/admin/services/'.$service->id.'/document-fields/'.$field->id, ['version' => 1])->assertSessionHasErrors('template');
        $this->assertDatabaseCount('document_fields', 1);
        $this->delete('/admin/services/'.$service->id.'/document-fields/'.$field->id, ['version' => 3])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('document_fields', 0);
        $this->assertSame($original, $this->originalRecords());
    }

    public function test_cross_service_field_ids_are_blocked_and_unique_names_scoped(): void
    {
        $first = $this->service();
        $second = $this->service('Residency');
        $this->configure($first);
        $this->configure($second);
        $this->add($first)->assertSessionHasNoErrors();
        $field = DocumentField::sole();
        $this->get('/admin/services/'.$second->id.'/document-fields/'.$field->id.'/edit')->assertNotFound();
        $this->put('/admin/services/'.$second->id.'/document-fields/'.$field->id, $this->fieldData($second))->assertNotFound();
        $this->delete('/admin/services/'.$second->id.'/document-fields/'.$field->id, ['version' => 1])->assertNotFound();
        $this->add($first)->assertSessionHasErrors('field_name');
        $this->add($second)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('document_fields', 2);
    }

    public function test_missing_stale_and_foreign_pdf_references_cannot_create_configuration(): void
    {
        $service = Service::create(['name' => 'Missing file']);
        $this->actingAs($this->user())->get($this->configUrl($service))->assertSee('Upload an official PDF first');
        $this->post($this->configUrl($service), ['is_active' => 1, 'pdf_id' => 999, 'pdf_revision' => str_repeat('a', 64)])->assertSessionHasErrors('template');
        $first = $this->service();
        $second = $this->service('Second');
        $this->configure($first, ['pdf_id' => $second->documentTemplate->id], false)->assertSessionHasErrors('template');
        $this->configure($first, ['pdf_revision' => str_repeat('a', 64)], false)->assertSessionHasErrors('template');
        Storage::disk('local')->delete($first->documentTemplate->stored_path);
        $this->configure($first, [], false)->assertSessionHasErrors('template');
        $this->assertDatabaseCount('document_templates', 0);
    }

    public function test_pdf_replacement_does_not_silently_rebind_configuration_or_change_print_source(): void
    {
        $service = $this->service();
        $this->configure($service);
        $config = $service->dynamicDocumentTemplate()->sole();
        $before = $config->getAttributes();
        $pdf = $service->documentTemplate;
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('z', 40).'.pdf';
        Storage::disk('local')->put($path, CertificatePdf::bytes('New official file'));
        $pdf->update(['stored_path' => $path]);
        $this->get($this->configUrl($service))->assertSee('official PDF has changed');
        $this->assertFalse($config->fresh()->matchesCurrentPdf());
        $this->assertSame($before, $config->fresh()->getAttributes());
        $this->assertSame($path, $service->documentTemplate()->sole()->stored_path);
    }

    public function test_active_setting_is_versioned_and_csrf_is_preserved(): void
    {
        $service = $this->service();
        $this->configure($service);
        $this->patch($this->configUrl($service), ['version' => 1, 'is_active' => 0, 'created_by' => 999])->assertSessionHasNoErrors();
        $config = $service->dynamicDocumentTemplate()->sole();
        $this->assertFalse($config->is_active);
        $this->assertSame(2, $config->version);
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/admin/services/'.$service->id.'/document-fields', $this->fieldData($service))->assertStatus(419);
        $this->assertDatabaseCount('document_fields', 0);
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }

    private function service(string $name = 'Generic Barangay Document'): Service
    {
        $service = Service::create(['name' => $name]);
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('a', 40).'.pdf';
        $pdf = CertificatePdf::bytes();
        Storage::disk('local')->put($path, $pdf);
        $service->documentTemplate()->create(['original_filename' => 'Official.pdf', 'stored_path' => $path, 'mime_type' => 'application/pdf',
            'file_size' => strlen($pdf), 'uploaded_at' => now()]);

        return $service;
    }

    private function configure(Service $service, array $extra = [], bool $assertSuccess = true)
    {
        if (! auth()->check()) {
            $this->actingAs($this->user());
        }
        $response = $this->post($this->configUrl($service), ['is_active' => 1, 'pdf_id' => $service->documentTemplate->id,
            'pdf_revision' => $service->documentTemplate->revision(), ...$extra]);
        if ($assertSuccess) {
            $response->assertSessionHasNoErrors();
        }

        return $response;
    }

    private function configUrl(Service $service): string
    {
        return '/admin/services/'.$service->id.'/document-configuration';
    }

    private function fieldData(Service $service): array
    {
        return ['version' => $service->dynamicDocumentTemplate()->sole()->version, 'field_name' => 'purpose', 'label' => 'Purpose',
            'source' => 'custom', 'field_type' => 'text', 'is_required' => 0, 'sort_order' => 0, 'has_position' => 0];
    }

    private function add(Service $service, array $extra = [])
    {
        return $this->post('/admin/services/'.$service->id.'/document-fields', [...$this->fieldData($service), ...$extra]);
    }

    private function originalRecords(): array
    {
        return collect(['services', 'service_document_templates', 'reservations', 'reservation_documents', 'qr_tickets', 'reservation_attachments', 'reservation_status_histories'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
