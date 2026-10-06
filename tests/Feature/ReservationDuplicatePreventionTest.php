<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationDuplicatePreventionTest extends TestCase
{
    private const MESSAGE = 'You already have an active reservation for this service and schedule.';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true,
            'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array',
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public static function activeStatuses(): array
    {
        return [[ReservationStatus::Pending], [ReservationStatus::UnderReview],
            [ReservationStatus::Approved], [ReservationStatus::ReadyForPickup]];
    }

    public static function terminalStatuses(): array
    {
        return [[ReservationStatus::Cancelled], [ReservationStatus::Rejected], [ReservationStatus::Completed]];
    }

    #[DataProvider('activeStatuses')]
    public function test_active_duplicate_is_rejected_without_changing_existing_records(ReservationStatus $status): void
    {
        $resident = $this->resident();
        $service = Service::create(['name' => 'Clearance']);
        $schedule = $this->schedule();
        $existing = $resident->reservations()->create([
            'service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status,
            'purpose' => 'Employment', 'admin_notes' => 'Preserve this review.',
        ]);
        $original = $existing->fresh()->getAttributes();
        $this->actingAs($resident);
        $token = $this->completeDraft($service, $schedule);

        $this->from(route('resident.reservations.confirm'))->post('/resident/reservations', [
            'confirmation_token' => $token, 'user_id' => $this->resident()->id,
        ])->assertRedirect(route('resident.reservations.confirm'))
            ->assertSessionHasErrors(['reservation' => self::MESSAGE]);
        $this->get('/resident/reservations/confirm')->assertOk()->assertSee(self::MESSAGE);
        $this->assertSame($token, session('reservation_wizard.confirmation_token'));
        $this->post('/resident/reservations', ['confirmation_token' => $token])
            ->assertSessionHasErrors(['reservation' => self::MESSAGE]);

        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame($original, $existing->fresh()->getAttributes());
        $this->assertSame(1, $schedule->occupiedReservations()->count());
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $this->assertDatabaseCount('qr_tickets', 0);
    }

    #[DataProvider('terminalStatuses')]
    public function test_terminal_previous_reservation_allows_new_request(ReservationStatus $status): void
    {
        $resident = $this->resident();
        $service = Service::create(['name' => 'Clearance']);
        $schedule = $this->schedule();
        $previous = $resident->reservations()->create([
            'service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status,
        ]);
        $original = $previous->fresh()->getAttributes();
        $this->assertSame(0, $schedule->occupiedReservations()->count());
        $this->actingAs($resident);
        $this->submit($service, $schedule);

        $this->assertDatabaseCount('reservations', 2);
        $this->assertSame($original, $previous->fresh()->getAttributes());
        $this->assertSame(1, $schedule->occupiedReservations()->count());
        $this->assertDatabaseHas('reservations', [
            'user_id' => $resident->id, 'service_id' => $service->id,
            'schedule_id' => $schedule->id, 'status' => ReservationStatus::Pending->value,
        ]);
    }

    public function test_another_resident_does_not_block_the_authenticated_resident(): void
    {
        $service = Service::create(['name' => 'Clearance']);
        $schedule = $this->schedule();
        $other = $this->resident()->reservations()->create([
            'service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => ReservationStatus::Pending,
        ]);
        $original = $other->fresh()->getAttributes();
        $current = $this->resident();
        $this->actingAs($current);
        $this->submit($service, $schedule);
        $this->assertDatabaseCount('reservations', 2);
        $this->assertSame($original, $other->fresh()->getAttributes());
        $this->assertSame(1, $current->reservations()->count());
    }

    public function test_different_service_or_schedule_is_allowed(): void
    {
        $this->actingAs($this->resident());
        $service = Service::create(['name' => 'Clearance']);
        $otherService = Service::create(['name' => 'Residency']);
        $schedule = $this->schedule();
        $otherSchedule = $this->schedule('2026-11-02');
        $this->submit($service, $schedule);
        $this->submit($otherService, $schedule);
        $this->submit($service, $otherSchedule);
        $this->assertDatabaseCount('reservations', 3);
    }

    public function test_consumed_token_and_fresh_wizard_cannot_create_another_active_reservation(): void
    {
        $this->actingAs($this->resident());
        $service = Service::create(['name' => 'Clearance']);
        $schedule = $this->schedule();
        $token = $this->completeDraft($service, $schedule);
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.home'));
        $this->assertNull(session('reservation_wizard'));
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.create'));
        $freshToken = $this->completeDraft($service, $schedule);
        $this->assertNotSame($token, $freshToken);
        $this->post('/resident/reservations', ['confirmation_token' => $freshToken])
            ->assertSessionHasErrors(['reservation' => self::MESSAGE]);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_confirmation_still_requires_csrf(): void
    {
        $this->actingAs($this->resident());
        $service = Service::create(['name' => 'Clearance']);
        $token = $this->completeDraft($service, $this->schedule());
        $this->app['env'] = 'local';
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertStatus(419);
        $this->assertDatabaseCount('reservations', 0);
    }

    private function completeDraft(Service $service, Schedule $schedule): string
    {
        $this->post('/resident/reservations/service', ['service_id' => $service->id])->assertRedirect(route('resident.reservations.requirements'));
        $this->post('/resident/reservations/requirements')->assertRedirect(route('resident.reservations.schedule'));
        $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id])->assertRedirect(route('resident.reservations.confirm'));

        return session('reservation_wizard.confirmation_token');
    }

    private function submit(Service $service, Schedule $schedule): void
    {
        $token = $this->completeDraft($service, $schedule);
        $this->post('/resident/reservations', ['confirmation_token' => $token])
            ->assertRedirect(route('resident.home'))->assertSessionHasNoErrors();
    }

    private function resident(): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => UserRole::Resident, 'is_active' => true])->save();

        return $user;
    }

    private function schedule(string $date = '2026-11-01'): Schedule
    {
        return Schedule::create(['date' => $date, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5]);
    }
}
