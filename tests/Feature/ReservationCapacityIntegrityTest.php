<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\ConfigurationAudit;
use App\Services\ReservationNotifications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReservationCapacityIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->travelTo(Carbon::parse('2026-11-13 08:00:00', 'Asia/Manila'));
        Storage::fake('local');
        $this->withoutVite();
    }

    public static function statuses(): array
    {
        return [[ReservationStatus::Pending, true], [ReservationStatus::UnderReview, true],
            [ReservationStatus::Approved, true], [ReservationStatus::ReadyForPickup, true],
            [ReservationStatus::Completed, false], [ReservationStatus::Rejected, false], [ReservationStatus::Cancelled, false]];
    }

    private function slot(int $capacity = 2): Schedule
    {
        return Schedule::create(['date' => now('Asia/Manila')->addDay()->toDateString(),
            'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => $capacity])->refresh();
    }

    private function prepare(User $resident, Service $service, Schedule $slot): string
    {
        $this->actingAs($resident);
        $this->post('/resident/reservations/service', ['service_id' => $service->id])->assertRedirect();
        $this->post('/resident/reservations/requirements', ['requirement_signature' => $this->requirementSignature()])->assertRedirect();
        $this->post('/resident/reservations/schedule', ['schedule_id' => $slot->id])->assertRedirect(route('resident.reservations.confirm'));

        return session('reservation_wizard.confirmation_token');
    }

    #[DataProvider('statuses')]
    public function test_status_policy_and_batched_portal_counts_agree_without_changing_history(ReservationStatus $status, bool $occupies): void
    {
        $slot = $this->slot();
        $service = Service::create(['name' => 'Synthetic service', 'fee_amount' => '0.00']);
        $reservation = User::factory()->residencyApproved()->create()->reservations()->create([
            'service_id' => $service->id, 'schedule_id' => $slot->id, 'status' => $status]);
        $original = $reservation->fresh()->getAttributes();
        $batched = Schedule::withCount('occupiedReservations')->findOrFail($slot->id);
        $this->assertSame($occupies ? 1 : 0, $slot->occupiedReservations()->count());
        $this->assertSame($slot->remainingSlots(), $batched->remainingSlots());
        $this->assertSame($occupies ? 1 : 2, $batched->remainingSlots());
        $this->assertSame(1, $slot->reservations()->count());
        $this->assertSame($original, $reservation->fresh()->getAttributes());
    }

    #[DataProvider('statuses')]
    public function test_admin_reduction_uses_the_same_status_policy(ReservationStatus $status, bool $occupies): void
    {
        $slot = $this->slot(3);
        $service = Service::create(['name' => 'Synthetic capacity edit', 'fee_amount' => '0.00']);
        foreach ([1, 2] as $unused) {
            User::factory()->residencyApproved()->create()->reservations()->create([
                'service_id' => $service->id, 'schedule_id' => $slot->id, 'status' => $status]);
        }
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]));
        $response = $this->put('/admin/schedules/'.$slot->id, [
            'date' => $slot->date->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00',
            'capacity' => 1, 'edit_revision' => app(ConfigurationAudit::class)->revision($slot),
        ]);
        if ($occupies) {
            $response->assertSessionHasErrors('capacity');
        } else {
            $response->assertSessionHasNoErrors();
        }
        $this->assertSame($occupies ? 3 : 1, $slot->fresh()->capacity);
        $this->assertSame(2, $slot->reservations()->where('status', $status)->count());
    }

    public function test_stale_confirmation_cannot_overbook_a_shared_schedule_or_leave_partial_records(): void
    {
        $slot = $this->slot(1);
        $service = Service::create(['name' => 'Synthetic stale request', 'fee_amount' => '0.00']);
        $token = $this->prepare(User::factory()->residencyApproved()->create(['role' => UserRole::Resident, 'is_active' => true]), $service, $slot);
        $otherService = Service::create(['name' => 'Synthetic other service', 'fee_amount' => '0.00']);
        $existing = User::factory()->residencyApproved()->create()->reservations()->create([
            'service_id' => $otherService->id, 'schedule_id' => $slot->id, 'status' => 'pending']);
        $original = $existing->fresh()->getAttributes();
        $this->post('/resident/reservations', ['confirmation_token' => $token])
            ->assertRedirect(route('resident.reservations.schedule'))->assertSessionHasErrors('reservation');
        $this->assertDatabaseCount('reservations', 1);
        foreach (['reservation_requirements', 'reservation_attachments', 'reservation_document_answers',
            'reservation_documents', 'reservation_payments', 'qr_tickets', 'notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($original, $existing->fresh()->getAttributes());
        $this->assertSame(0, $slot->fresh()->remainingSlots());
        $this->assertNull(session('reservation_wizard.confirmation_token'));
    }

    public function test_failure_after_notification_writes_rolls_back_the_entire_booking_and_releases_capacity(): void
    {
        $slot = $this->slot(1);
        $service = Service::create(['name' => 'Synthetic rollback', 'fee_amount' => '0.00']);
        $token = $this->prepare(User::factory()->residencyApproved()->create(['role' => UserRole::Resident, 'is_active' => true]), $service, $slot);
        $this->app->bind(ReservationNotifications::class, fn () => new class extends ReservationNotifications
        {
            public function record(string $event, Reservation $reservation, ?Model $source = null): void
            {
                parent::record($event, $reservation, $source);
                throw new RuntimeException('Synthetic rollback boundary.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/resident/reservations', ['confirmation_token' => $token]);
            $this->fail('Synthetic failure must abort booking.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic rollback boundary.', $exception->getMessage());
        }
        foreach (['reservations', 'reservation_requirements', 'reservation_attachments', 'reservation_document_answers',
            'reservation_documents', 'reservation_payments', 'qr_tickets', 'notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(1, $slot->fresh()->remainingSlots());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_inconsistent_legacy_occupancy_never_displays_negative_remaining_capacity(): void
    {
        $slot = $this->slot(1);
        $service = Service::create(['name' => 'Synthetic historical over-capacity fixture', 'fee_amount' => '0.00']);
        foreach ([1, 2] as $unused) {
            User::factory()->residencyApproved()->create()->reservations()->create([
                'service_id' => $service->id, 'schedule_id' => $slot->id, 'status' => 'pending']);
        }
        $this->assertSame(0, $slot->fresh()->remainingSlots());
        $this->assertSame(2, $slot->reservations()->count());
    }
}
