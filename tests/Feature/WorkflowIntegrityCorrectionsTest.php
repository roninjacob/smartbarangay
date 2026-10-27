<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\AccountEligibility;
use App\Services\CheckInReservations;
use App\Services\ConfigurationAudit;
use App\Services\GeneratedReservationDocuments;
use App\Services\QrTicketIssuer;
use App\Services\RequirementChecklist;
use App\Services\ReservationPayments;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkflowIntegrityCorrectionsTest extends TestCase
{
    private User $resident;

    private Service $service;

    private Schedule $slot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Storage::fake('local');
        $this->withoutVite();
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 27)->setTime(10, 0));
        $this->resident = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $this->service = Service::create(['name' => 'Clearance', 'fee_amount' => '50.00']);
        $this->slot = Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 20]);
        $this->actingAs($this->resident);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function review(): string
    {
        $this->post('/resident/reservations/service', ['service_id' => $this->service->id])->assertSessionHasNoErrors();

        return $this->get('/resident/reservations/requirements')->assertOk()->viewData('requirementSignature');
    }

    private function confirm(): string
    {
        $signature = $this->review();
        $this->post('/resident/reservations/requirements', ['requirement_signature' => $signature])->assertRedirect(route('resident.reservations.schedule'));
        $this->post('/resident/reservations/schedule', ['schedule_id' => $this->slot->id])->assertSessionHasNoErrors();

        return session('reservation_wizard.confirmation_token');
    }

    public static function checklistChanges(): array
    {
        return [['name'], ['description'], ['is_required'], ['add'], ['delete']];
    }

    private function changeChecklist(string $change, $requirement): void
    {
        match ($change) {
            'name' => $requirement->update(['name' => 'Proof of Income']),
            'description' => $requirement->update(['description' => 'Updated instructions']),
            'is_required' => $requirement->update(['is_required' => true]),
            'add' => $this->service->serviceRequirements()->create(['name' => 'New proof', 'is_required' => false]),
            'delete' => $requirement->delete(),
        };
    }

    #[DataProvider('checklistChanges')]
    public function test_changes_after_render_require_a_new_review(string $change): void
    {
        $requirement = $this->service->serviceRequirements()->create(['name' => 'Valid ID', 'is_required' => false]);
        $signature = $this->review();
        $this->changeChecklist($change, $requirement);
        $this->post('/resident/reservations/requirements', ['requirement_signature' => $signature])
            ->assertRedirect(route('resident.reservations.requirements'))->assertSessionHasErrors('reservation')
            ->assertSessionMissing('reservation_wizard.requirements_reviewed');
        $this->assertDatabaseCount('reservations', 0);
    }

    #[DataProvider('checklistChanges')]
    public function test_changes_after_confirmation_cannot_create_a_mislabeled_snapshot(string $change): void
    {
        $requirement = $this->service->serviceRequirements()->create(['name' => 'Valid ID', 'is_required' => false]);
        $token = $this->confirm();
        $this->changeChecklist($change, $requirement);
        $this->post('/resident/reservations', ['confirmation_token' => $token])
            ->assertRedirect(route('resident.reservations.requirements'))->assertSessionHasErrors('reservation')
            ->assertSessionMissing('reservation_wizard.confirmation_token');
        foreach (['reservations', 'reservation_requirements', 'reservation_attachments', 'notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_missing_tampered_and_other_service_signatures_fail_closed(): void
    {
        $this->review();
        $other = Service::create(['name' => 'Other', 'fee_amount' => '0.00']);
        foreach ([null, str_repeat('a', 64), [], app(RequirementChecklist::class)->signature($other->id, collect())] as $signature) {
            $this->post('/resident/reservations/requirements', ['requirement_signature' => $signature])
                ->assertSessionHasErrors('reservation')->assertSessionMissing('reservation_wizard.requirements_reviewed');
        }
    }

    public function test_rereview_succeeds_and_the_snapshot_survives_later_master_edits(): void
    {
        $requirement = $this->service->serviceRequirements()->create(['name' => 'Valid ID', 'is_required' => false]);
        $token = $this->confirm();
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertSessionHasNoErrors();
        $requirement->update(['name' => 'New name']);
        $this->assertSame('Valid ID', Reservation::sole()->requirementSnapshots->sole()->name);
    }

    public static function actorChanges(): array
    {
        return [['is_active', false], ['email_verified_at', null], ['role', 'resident']];
    }

    #[DataProvider('actorChanges')]
    public function test_stale_admin_cannot_pay_check_in_or_finalize(string $field, mixed $value): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $record = Reservation::create(['user_id' => $this->resident->id, 'service_id' => $this->service->id,
            'schedule_id' => $this->slot->id, 'status' => ReservationStatus::ReadyForPickup]);
        $record->forceFill(['fee_snapshot' => '50.00'])->save();
        $ticket = app(QrTicketIssuer::class)->issue($record);
        $evidence = app(CheckInReservations::class)->evidence($ticket, $admin);
        User::whereKey($admin->id)->update([$field => $value]);
        foreach ([fn () => app(ReservationPayments::class)->record($record, $admin),
            fn () => app(CheckInReservations::class)->record($record, $admin, $evidence),
            fn () => app(GeneratedReservationDocuments::class)->generate($record, $admin)] as $write) {
            try {
                $write();
                $this->fail('A stale Admin must not write.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        foreach (['reservation_payments', 'checkin_logs', 'reservation_documents', 'reservation_status_histories', 'notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(ReservationStatus::ReadyForPickup, $record->fresh()->status);
        $this->assertNull($ticket->fresh()->used_at);
    }

    public function test_stale_admin_cannot_change_status_or_configuration(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $record = Reservation::create(['user_id' => $this->resident->id, 'service_id' => $this->service->id,
            'schedule_id' => $this->slot->id, 'status' => ReservationStatus::Pending]);
        $this->actingAs($admin);
        User::whereKey($admin->id)->update(['is_active' => false]);
        $this->patch(route('admin.reservations.status', $record), ['expected_status' => 'pending', 'status' => 'under_review'])->assertForbidden();
        $this->put(route('admin.services.update', $this->service), ['name' => 'Changed', 'fee_amount' => '1.00',
            'edit_revision' => app(ConfigurationAudit::class)->revision($this->service)])->assertForbidden();
        $this->assertSame(ReservationStatus::Pending, $record->fresh()->status);
        $this->assertSame('Clearance', $this->service->fresh()->name);
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_schedule_actions_preserve_only_validated_local_context(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->actingAs($admin);
        $context = ['view' => 'upcoming', 'status' => 'active', 'from' => '2026-11-01', 'to' => '2026-11-30', 'page' => 2];
        $this->patch(route('admin.schedules.status', $this->slot), ['is_active' => false, 'return_filters' => $context])
            ->assertRedirect(route('admin.schedules.index', $context));
        $this->slot->refresh();
        $this->patch(route('admin.schedules.status', $this->slot), ['is_active' => true, 'return_filters' => ['url' => 'https://example.com']])
            ->assertSessionHasErrors('return_filters');
        $this->assertFalse($this->slot->fresh()->is_active);
        $this->get(route('admin.schedules.edit', ['schedule' => $this->slot, 'return_filters' => $context]))
            ->assertOk()->assertSee('name="return_filters[page]" value="2"', false);
    }

    public function test_out_of_range_slot_page_has_recovery_without_false_empty_claim(): void
    {
        $this->confirm();
        $this->get('/resident/reservations/schedule?slots_page=999&date=2026-11-01')->assertOk()
            ->assertSee('No slots on this page')->assertSee('Back to first page')->assertDontSee('No slots for this date')->assertDontSee('Showing – of');
        $this->get('/resident/reservations/schedule?date=2026-11-02')->assertOk()->assertSee('No slots for this date');
    }

    public function test_eligibility_cannot_be_checked_outside_a_transaction(): void
    {
        $this->expectException(\LogicException::class);
        app(AccountEligibility::class)->lock($this->resident, UserRole::Resident);
    }
}
