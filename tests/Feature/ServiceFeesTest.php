<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\ConfigurationAudit;
use App\Support\ServiceFee;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceFeesTest extends TestCase
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

    private function user(UserRole $role = UserRole::Resident): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function schedule(): Schedule
    {
        return Schedule::create(['date' => '2026-12-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10]);
    }

    private function draft(Service $service, Schedule $schedule): string
    {
        $this->post('/resident/reservations/service', ['service_id' => $service->id])->assertRedirect();
        $this->get('/resident/reservations/requirements')->assertOk();
        $this->post('/resident/reservations/requirements', ['requirement_signature' => $this->requirementSignature()])->assertSessionHasNoErrors();
        $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id])->assertSessionHasNoErrors();
        $this->get('/resident/reservations/confirm')->assertOk();

        return session('reservation_wizard.confirmation_token');
    }

    public static function validFees(): array
    {
        return [['0.00', '0.00'], ['50', '50.00'], ['1250.5', '1250.50'], ['99999999.99', '99999999.99']];
    }

    #[DataProvider('validFees')]
    public function test_admin_creates_explicit_fixed_decimal_fee(string $fee, string $expected): void
    {
        $this->actingAs($this->user(UserRole::Admin))->post('/admin/services', ['name' => 'Clearance', 'fee_amount' => $fee])->assertSessionHasNoErrors();
        $this->assertSame($expected, Service::sole()->fee_amount);
    }

    public static function invalidFees(): array
    {
        return array_map(fn ($value) => [$value], [null, '', '-1', 'abc', '50.001', '1e2', '1,250.00', '₱50', '100000000', ['50'], true]);
    }

    #[DataProvider('invalidFees')]
    public function test_invalid_fees_are_rejected_on_create_and_edit(mixed $fee): void
    {
        $service = Service::create(['name' => 'Original', 'fee_amount' => '50.00']);
        $this->actingAs($this->user(UserRole::Admin));
        $this->post('/admin/services', ['name' => 'New', 'fee_amount' => $fee])->assertSessionHasErrors('fee_amount');
        $this->put('/admin/services/'.$service->id, ['edit_revision' => app(ConfigurationAudit::class)->revision($service->fresh()), 'name' => 'Original', 'fee_amount' => $fee])->assertSessionHasErrors('fee_amount');
        $this->assertSame('50.00', $service->fresh()->fee_amount);
        $this->assertDatabaseCount('services', 1);
    }

    public function test_legacy_service_requires_explicit_setup_and_resident_cannot_change_fee(): void
    {
        $service = Service::create(['name' => 'Legacy']);
        $this->actingAs($this->user(UserRole::Admin))->get('/admin/services/'.$service->id.'/edit')->assertSee('Fee setup required')->assertSee('Existing reservations retain their original fee.');
        $this->put('/admin/services/'.$service->id, ['edit_revision' => app(ConfigurationAudit::class)->revision($service->fresh()), 'name' => 'Legacy'])->assertSessionHasErrors('fee_amount');
        $this->assertNull($service->fresh()->fee_amount);
        $this->put('/admin/services/'.$service->id, ['edit_revision' => app(ConfigurationAudit::class)->revision($service->fresh()), 'name' => 'Legacy', 'fee_amount' => '0.00'])->assertSessionHasNoErrors();
        $this->actingAs($this->user());
        $this->post('/admin/services', ['name' => 'Injected', 'fee_amount' => '1.00'])->assertForbidden();
        $this->put('/admin/services/'.$service->id, ['edit_revision' => app(ConfigurationAudit::class)->revision($service->fresh()), 'name' => 'Legacy', 'fee_amount' => '999.00'])->assertForbidden();
        $this->assertSame('0.00', $service->fresh()->fee_amount);
    }

    #[DataProvider('validFees')]
    public function test_submission_snapshots_authoritative_fee_and_ignores_posted_money(string $fee, string $expected): void
    {
        $resident = $this->user();
        $service = Service::create(['name' => 'Clearance', 'fee_amount' => $fee]);
        $this->actingAs($resident);
        $this->get('/resident/reservations/create')->assertSee(ServiceFee::format($expected));
        $token = $this->draft($service, $this->schedule());
        $this->get('/resident/reservations/confirm')->assertSee(ServiceFee::format($expected));
        $this->post('/resident/reservations', ['confirmation_token' => $token, 'fee_snapshot' => '0.01', 'fee_amount' => '0.01', 'reviewed_fee' => '0.01'])->assertRedirect(route('resident.home'));
        $reservation = Reservation::sole();
        $this->assertSame($expected, $reservation->fee_snapshot);
        $this->assertFalse($reservation->isFillable('fee_snapshot'));
        $newFee = $expected === '75.00' ? '80.00' : '75.00';
        $this->actingAs($this->user(UserRole::Admin))->put('/admin/services/'.$service->id, ['edit_revision' => app(ConfigurationAudit::class)->revision($service->fresh()), 'name' => 'Clearance', 'fee_amount' => $newFee])->assertSessionHasNoErrors();
        $this->assertSame($expected, $reservation->fresh()->fee_snapshot);
        $this->get('/admin/reservations/'.$reservation->id)->assertSee(ServiceFee::format($expected));
        $this->actingAs($resident)->get('/resident/reservations/'.$reservation->id)->assertSee(ServiceFee::format($expected));
    }

    public function test_unconfigured_service_blocks_selection_and_existing_draft_final_submission(): void
    {
        $service = Service::create(['name' => 'Needs fee']);
        $this->actingAs($this->user());
        $this->post('/resident/reservations/service', ['service_id' => $service->id])->assertRedirect(route('resident.reservations.create'))->assertSessionHasErrors('reservation');
        $service->update(['fee_amount' => '50.00']);
        $token = $this->draft($service, $this->schedule());
        $service->update(['fee_amount' => null]);
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.create'))->assertSessionHasErrors('reservation');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_changed_fee_requires_review_before_submission_then_snapshots_new_fee(): void
    {
        $service = Service::create(['name' => 'Clearance', 'fee_amount' => '50.00']);
        $schedule = $this->schedule();
        $this->actingAs($this->user());
        $token = $this->draft($service, $schedule);
        $service->update(['fee_amount' => '75.00']);
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.requirements'))->assertSessionHasErrors('reservation');
        $this->assertDatabaseCount('reservations', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        foreach (['reservation_document_answers', 'reservation_status_histories', 'qr_tickets', 'reservation_attachments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertNull(session('reservation_wizard.confirmation_token'));
        $this->get('/resident/reservations/requirements')->assertSee('₱75.00')->assertSee('service fee changed');
        $this->post('/resident/reservations/requirements', ['requirement_signature' => $this->requirementSignature()])->assertSessionHasNoErrors();
        $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id]);
        $newToken = session('reservation_wizard.confirmation_token');
        $this->assertNotSame($token, $newToken);
        $this->post('/resident/reservations', ['confirmation_token' => $newToken])->assertRedirect(route('resident.home'));
        $this->assertSame('75.00', Reservation::sole()->fee_snapshot);
    }

    public function test_fee_change_between_information_page_and_review_is_not_silently_accepted(): void
    {
        $service = Service::create(['name' => 'Clearance', 'fee_amount' => '50.00']);
        $this->actingAs($this->user());
        $this->post('/resident/reservations/service', ['service_id' => $service->id]);
        $this->get('/resident/reservations/requirements')->assertSee('₱50.00');
        $service->update(['fee_amount' => '75.00']);
        $this->post('/resident/reservations/requirements', ['requirement_signature' => $this->requirementSignature()])->assertSessionHasErrors('reservation');
        $this->assertFalse((bool) session('reservation_wizard.requirements_reviewed'));
    }

    public function test_migration_preserves_legacy_nulls_and_detail_views_never_substitute_current_fee(): void
    {
        $migration = require database_path('migrations/2026_10_16_000019_add_service_fees_and_reservation_fee_snapshots.php');
        $migration->down();
        $resident = $this->user();
        $service = Service::create(['name' => 'Legacy']);
        $reservation = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $this->schedule()->id, 'status' => 'pending']);
        $migration->up();
        $this->assertNull($service->fresh()->fee_amount);
        $this->assertNull($reservation->fresh()->fee_snapshot);
        $service->update(['fee_amount' => '1234.56']);
        $this->actingAs($resident)->get('/resident/reservations/'.$reservation->id)->assertSee('Not recorded')->assertDontSee('₱1,234.56');
        $this->actingAs($this->user(UserRole::Admin))->get('/admin/reservations/'.$reservation->id)->assertSee('Not recorded')->assertDontSee('₱1,234.56');
        $this->assertNull($reservation->fresh()->fee_snapshot);
        $this->assertSame('₱1,250.50', ServiceFee::format('1250.50'));
        $this->assertSame('₱0.00', ServiceFee::format('0.00'));
    }
}
