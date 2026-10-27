<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DocumentField;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\FinalCertificatePdf;
use App\Services\ReservationDocumentInformation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Tfpdf\Fpdi;
use Tests\TestCase;

class Milestone3AcceptanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Notification::fake();
        Storage::fake('local');
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-10-25 09:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function source(): string
    {
        $pdf = new Fpdi('P', 'pt');
        foreach ([[595.28, 841.89], [612, 792]] as $index => $size) {
            $pdf->AddPage('P', $size);
            $pdf->SetFont('Helvetica', 'B', 15);
            $pdf->Text(36, 42, 'SMARTBARANGAY - ACCEPTANCE FIXTURE');
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->Text(36, 60, 'SYNTHETIC DATA ONLY - NOT AN OFFICIAL CERTIFICATE');
            $pdf->Rect(24, 24, $size[0] - 48, $size[1] - 48);
            $pdf->Text(36, $size[1] - 38, 'Page '.($index + 1).' - original '.($index === 0 ? 'A4' : 'Letter').' dimensions');
        }

        return $pdf->Output('S');
    }

    public function test_controlled_registration_to_reviewed_final_pdf_and_print_with_long_text(): void
    {
        $admin = User::factory()->create(['name' => 'Acceptance Admin', 'role' => UserRole::Admin, 'is_active' => true]);
        $this->post(route('register.store'), ['name' => 'Acceptance Resident', 'email' => 'acceptance-resident@example.test',
            'password' => 'Acceptance123!', 'password_confirmation' => 'Acceptance123!', 'contact_number' => '09123456789',
            'address' => 'Synthetic legacy account address'])->assertRedirect(route('verification.notice'));
        $resident = User::where('email', 'acceptance-resident@example.test')->sole();
        $this->assertSame(UserRole::Resident, $resident->role);
        $this->assertNull($resident->email_verified_at);
        $this->get(route('resident.profile.edit'))->assertRedirect(route('verification.notice'));
        $verification = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $resident->id, 'hash' => sha1($resident->email)]);
        $this->get($verification)->assertRedirect(route('resident.profile.edit'));
        $this->get(route('resident.profile.edit'))->assertOk()->assertSee('Prepare your profile next.');
        $occupation = 'Community Development and Disaster Preparedness Coordination Assistant';
        $street = trim(str_repeat('Synthetic Coastal Access Road, ', 7));
        $this->put(route('resident.profile.demographics.update'), ['birthdate' => '2000-02-29', 'civil_status' => 'Single',
            'occupation' => $occupation, 'address_street' => $street, 'address_zone_purok' => 'Purok 1',
            'emergency_contact_name' => null, 'emergency_contact_number' => null])->assertRedirect(route('resident.profile.edit'))->assertSessionHasNoErrors();

        $service = Service::create(['name' => 'Synthetic Acceptance Certificate', 'fee_amount' => '0.00', 'is_active' => true]);
        $source = $this->source();
        $path = 'service-document-templates/'.$service->id.'/'.str_repeat('a', 40).'.pdf';
        Storage::disk('local')->put($path, $source);
        $master = $service->documentTemplate()->create(['original_filename' => 'Synthetic-A4-Letter.pdf', 'stored_path' => $path,
            'mime_type' => 'application/pdf', 'file_size' => strlen($source), 'uploaded_at' => now()]);
        $template = $service->dynamicDocumentTemplate()->make();
        $template->forceFill(['service_document_template_id' => $master->id, 'source_revision' => $master->revision(),
            'source_content_hash' => hash('sha256', $source), 'source_filename' => 'Synthetic-A4-Letter.pdf', 'version' => 1, 'is_active' => true])->save();
        $specs = [
            ['resident_name', 'Resident name', 95, 30], ['resident_civil_status', 'Civil status', 150, 30],
            ['resident_occupation', 'Occupation - long text', 205, 64], ['resident_birthdate', 'Birthdate', 300, 30],
            ['resident_address_street', 'Street - long text', 355, 105], ['resident_address_zone_purok', 'Zone / Purok', 500, 30],
            ['optional_note', 'Optional note', 560, 30],
        ];
        foreach ($specs as $index => [$name, $label, $y, $height]) {
            $field = $template->fields()->create(['field_name' => $name, 'label' => $label, 'source' => $name === 'optional_note' ? 'custom' : 'system',
                'field_type' => DocumentField::SYSTEM_FIELDS[$name] ?? 'text', 'is_required' => $name !== 'optional_note', 'sort_order' => $index]);
            $field->positions()->create(['page_number' => 1, 'x_position' => 48, 'y_position' => $y, 'width' => 280,
                'height' => $height, 'font_size' => 12, 'alignment' => 'left', 'text_behavior' => 'wrap']);
            if ($name === 'resident_occupation') {
                $field->positions()->create(['page_number' => 2, 'x_position' => 36, 'y_position' => 720, 'width' => 540,
                    'height' => 24, 'font_size' => 12, 'alignment' => 'left', 'text_behavior' => 'wrap']);
            }
        }
        $schedule = Schedule::create(['date' => '2026-10-26', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5, 'is_active' => true]);
        $this->post(route('resident.reservations.service'), ['service_id' => $service->id])->assertRedirect();
        $this->get(route('resident.reservations.requirements'))->assertOk()->assertSee($occupation);
        $this->post(route('resident.reservations.requirements.review'), ['requirement_signature' => $this->requirementSignature(),
            'document_signature' => app(ReservationDocumentInformation::class)->signature($template->load('fields')),
        ])->assertRedirect(route('resident.reservations.schedule'));
        $this->post(route('resident.reservations.schedule.select'), ['schedule_id' => $schedule->id])->assertRedirect();
        $this->get(route('resident.reservations.confirm'))->assertOk();
        $this->post(route('resident.reservations.store'), ['confirmation_token' => session('reservation_wizard.confirmation_token')])
            ->assertRedirect(route('resident.home'))->assertSessionHasNoErrors();
        $reservation = Reservation::sole();
        $this->assertSame($street, $reservation->resident_snapshot['resident_address_street']);
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('admin.home'));
        foreach ([['pending', 'under_review'], ['under_review', 'approved']] as [$from,$to]) {
            $this->patch(route('admin.reservations.status', $reservation), ['expected_status' => $from, 'status' => $to])->assertSessionHasNoErrors();
        }
        $reservation->refresh();
        $this->assertNotNull($reservation->qrTicket);
        $before = $reservation->getAttributes();
        $histories = $reservation->statusHistories()->count();
        $preview = $this->get(route('admin.reservations.document-preview.show', $reservation))->assertOk()
            ->assertSee('Not provided')->assertDontSee("\u{FFFD}", false);
        $token = $preview->viewData('artifact')['token'];
        $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $token])->assertSessionHasErrors('document');
        $previewUrl = route('admin.reservations.document-preview.pdf', [$reservation, 'preview' => $token]);
        $reviewed = $this->get($previewUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->post(route('admin.reservations.generated.store', $reservation), ['preview_token' => $token])->assertSessionHasNoErrors();
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        $this->assertSame($histories, $reservation->statusHistories()->count());
        $this->assertNull($reservation->qrTicket->fresh()->used_at);
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->assertDatabaseCount('reservation_payments', 0);
        $printUrl = $this->get(route('admin.reservations.document.print', $reservation))->assertRedirect()->headers->get('Location');
        $print = $this->get($printUrl)->assertOk()->assertSee('data-print-document', false)->assertSee('data-certificate-browser-print', false);
        $this->assertSame($reviewed, $this->get($printUrl.'/pdf')->assertOk()->getContent());
        $reader = new Fpdi('P', 'pt');
        $this->assertSame(2, $reader->setSourceFile(Storage::disk('local')->path($reservation->fresh()->document->stored_path)));
        $sizes = [];
        foreach ([[595.28, 841.89], [612, 792]] as $index => [$width,$height]) {
            $size = $reader->getTemplateSize($reader->importPage($index + 1));
            $this->assertEqualsWithDelta($width, $size['width'], .02);
            $this->assertEqualsWithDelta($height, $size['height'], .02);
            $sizes[] = ['width' => $size['width'], 'height' => $size['height']];
        }
        $directory = getenv('SMARTBARANGAY_ACCEPTANCE_ARTIFACT_DIR');
        if (is_string($directory) && $directory !== '') {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/certificate.pdf', $reviewed);
            // A static mirror of the actual print Blade response for visual QA; never a live token bypass.
            $html = str_replace($printUrl.'/pdf', '/certificate.pdf', $print->getContent());
            $html = preg_replace('#href="https?://[^"]*"#', 'href="#"', $html);
            $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
            $assets = '<link rel="stylesheet" href="/build/'.$manifest['resources/css/app.css']['file'].'"><script type="module" src="/build/'.$manifest['resources/js/app.js']['file'].'"></script>';
            file_put_contents($directory.'/print.html', str_replace('</head>', $assets.'</head>', $html));
            file_put_contents($directory.'/acceptance.json', json_encode(['environment' => 'isolated SQLite + synthetic data',
                'page_sizes_points' => $sizes, 'exact_reviewed_bytes' => true, 'sha256' => hash('sha256', $reviewed),
                'qr_issued' => true, 'qr_consumed' => false, 'status_after_print' => 'approved'], JSON_PRETTY_PRINT));
        }
    }

    public function test_text_that_cannot_fit_fails_instead_of_clipping(): void
    {
        $path = Storage::disk('local')->path('source.pdf');
        Storage::disk('local')->put('source.pdf', $this->source());
        $this->expectException(ValidationException::class);
        app(FinalCertificatePdf::class)->render($path, [['page_number' => 1, 'x_position' => 40, 'y_position' => 100,
            'width' => 4, 'height' => 4, 'font_size' => 12, 'alignment' => 'left', 'text_behavior' => 'wrap', 'text' => str_repeat('Long text ', 20)]]);
    }
}
