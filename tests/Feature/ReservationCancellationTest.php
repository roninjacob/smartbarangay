<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus as Status;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReservationCancellationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true,
            'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_cancel_is_owned_pending_only_and_records_reason_actor_and_time(): void
    {
        $resident = $this->user();
        $reservation = $this->reservation($resident);
        $reservation->qrTicket()->create(['ticket_code' => 'FOUNDATION-TICKET', 'qr_payload' => 'FOUNDATION-TICKET', 'generated_at' => now()]);
        $before = $reservation->fresh()->getRawOriginal();
        $this->travelTo(now()->startOfSecond());
        $this->actingAs($resident)->post($this->path($reservation), ['reason' => '  No longer needed.  ', 'status' => 'approved', 'user_id' => 999, 'changed_by' => 999])
            ->assertRedirect(route('resident.request-status.show', $reservation))->assertSessionHas('status');
        $this->assertSame(Status::Cancelled, $reservation->fresh()->status);
        $history = $reservation->statusHistories()->sole();
        $this->assertSame(Status::Pending, $history->from_status);
        $this->assertSame(Status::Cancelled, $history->to_status);
        $this->assertSame($resident->id, $history->changed_by);
        $this->assertSame('No longer needed.', $history->notes);
        $this->assertTrue($history->changed_at->equalTo(now()));
        foreach (['user_id', 'service_id', 'schedule_id', 'purpose', 'admin_notes', 'created_at'] as $field) {
            $this->assertSame($before[$field], $reservation->fresh()->getRawOriginal($field));
        }
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('qr_tickets', 1);
        $this->assertTrue($reservation->fresh()->status->blocksQrEligibility());
        $this->assertSame([], Status::Cancelled->allowedTransitions());
        $this->get(route('resident.request-status.show', $reservation))->assertSee('Cancelled')->assertSee('Your cancellation reason: No longer needed.')->assertDontSee('Cancel reservation')->assertDontSee('aria-current="step"', false);
        $this->get(route('resident.reservations.show', $reservation))->assertSee('Cancelled')->assertDontSee('Cancel reservation');
    }

    public function test_guest_wrong_role_unverified_inactive_and_foreign_resident_cannot_cancel(): void
    {
        $resident = $this->user();
        $reservation = $this->reservation($resident);
        $data = ['reason' => 'Not needed'];
        $this->post($this->path($reservation), $data)->assertRedirect(route('login'));
        $this->actingAs($this->user(UserRole::Admin))->post($this->path($reservation), $data)->assertForbidden();
        $resident->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($resident)->post($this->path($reservation), $data)->assertRedirect(route('verification.notice'));
        $resident->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($resident)->post($this->path($reservation), $data)->assertRedirect(route('login'));
        $this->actingAs($this->user())->post($this->path($reservation), $data)->assertNotFound();
        $this->post('/resident/reservations/999/cancel', $data)->assertNotFound();
        $this->assertSame(Status::Pending, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public function test_every_non_pending_status_rejects_cancellation_and_hides_controls(): void
    {
        $resident = $this->user();
        $this->actingAs($resident);
        foreach (Status::cases() as $status) {
            $reservation = $this->reservation($resident, $status);
            foreach (['resident.reservations.show', 'resident.request-status.show'] as $route) {
                $response = $this->get(route($route, $reservation))->assertOk();
                $status === Status::Pending ? $response->assertSee('Cancel reservation') : $response->assertDontSee('Cancel reservation');
            }
            if ($status === Status::Pending) {
                continue;
            }
            $this->from(route('resident.request-status.show', $reservation))->post($this->path($reservation), ['reason' => 'No longer needed'])->assertSessionHasErrors('reason');
            $this->assertSame($status, $reservation->fresh()->status);
        }
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public function test_reason_validation_csrf_and_no_deletion_routes(): void
    {
        $resident = $this->user();
        $reservation = $this->reservation($resident);
        $this->actingAs($resident);
        foreach ([null, '', '   ', ['bad'], str_repeat('x', 2001)] as $reason) {
            $this->post($this->path($reservation), ['reason' => $reason])->assertSessionHasErrors('reason');
        }
        $this->delete(route('resident.reservations.show', $reservation))->assertStatus(405);
        $this->actingAs($this->user(UserRole::Admin))->delete(route('admin.reservations.show', $reservation))->assertStatus(405);
        $this->actingAs($resident);
        $this->app['env'] = 'local';
        $this->post($this->path($reservation), ['reason' => 'No longer needed'])->assertStatus(419);
        $this->assertSame(Status::Pending, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public function test_repeated_cancel_and_admin_processing_cannot_overwrite_terminal_state(): void
    {
        $resident = $this->user();
        $reservation = $this->reservation($resident);
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($resident)->post($this->path($reservation), ['reason' => 'First reason'])->assertSessionHas('status');
        $this->post($this->path($reservation), ['reason' => 'Second reason'])->assertSessionHasErrors('reason');
        $this->actingAs($admin);
        foreach (Status::cases() as $next) {
            $this->patch(route('admin.reservations.status', $reservation), ['status' => $next->value, 'expected_status' => 'cancelled', 'notes' => 'Admin note'])->assertSessionHasErrors('status');
        }
        $this->patch(route('admin.reservations.status', $reservation), ['status' => 'under_review', 'expected_status' => 'pending'])->assertSessionHasErrors('status');
        $this->assertSame(Status::Cancelled, $reservation->fresh()->status);
        $this->assertSame('First reason', $reservation->statusHistories()->sole()->notes);
        $this->assertDatabaseCount('reservation_status_histories', 1);
        $this->get('/admin/reservations?status=cancelled')->assertOk()->assertSee('Cancelled');
        $this->get(route('admin.reservations.show', $reservation))->assertSee('No further status changes')->assertDontSee('Update status');
    }

    public function test_admin_review_winning_first_prevents_resident_cancellation(): void
    {
        $resident = $this->user();
        $reservation = $this->reservation($resident);
        $this->actingAs($this->user(UserRole::Admin))->patch(route('admin.reservations.status', $reservation), ['status' => 'under_review', 'expected_status' => 'pending'])->assertSessionHas('status');
        $this->actingAs($resident)->post($this->path($reservation), ['reason' => 'Stale cancellation form'])->assertSessionHasErrors('reason');
        $this->assertSame(Status::UnderReview, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_histories', 1);
    }

    public function test_history_failure_rolls_back_status_and_occupancy(): void
    {
        $resident = $this->user();
        $reservation = $this->reservation($resident);
        DB::statement("CREATE TRIGGER fail_cancellation_history BEFORE INSERT ON reservation_status_histories BEGIN SELECT RAISE(ABORT, 'Simulated history failure'); END");
        $this->actingAs($resident)->withoutExceptionHandling();
        try {
            $this->post($this->path($reservation), ['reason' => 'No longer needed']);
            $this->fail('Expected simulated history failure');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Simulated history failure', $exception->getMessage());
        }
        $this->assertSame(Status::Pending, $reservation->fresh()->status);
        $this->assertSame(1, $reservation->schedule->occupiedReservations()->count());
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public function test_single_occupancy_scope_excludes_cancelled_rejected_completed_and_preserves_schedule_links(): void
    {
        $resident = $this->user();
        foreach (Status::cases() as $status) {
            $this->reservation($resident, $status);
        }
        $schedule = Schedule::first();
        $this->assertSame(4, $schedule->occupiedReservations()->count());
        $this->assertSame(4, Reservation::occupyingSlot()->count());
        $this->assertSame(7, $schedule->reservations()->count());
        $pending = $resident->reservations()->where('status', 'pending')->first();
        $this->actingAs($resident)->post($this->path($pending), ['reason' => 'No longer needed'])->assertSessionHas('status');
        $this->assertSame(3, $schedule->occupiedReservations()->count());
        $this->assertSame(5, $schedule->fresh()->capacity);
        $this->actingAs($this->user(UserRole::Admin))->delete(route('admin.schedules.destroy', $schedule))->assertSessionHasErrors('schedule');
        $this->assertDatabaseCount('reservations', 7);
    }

    public function test_migration_preserves_existing_data_and_refuses_lossy_rollback(): void
    {
        $resident = $this->user();
        $reservation = $this->reservation($resident);
        $migration = require database_path('migrations/2026_10_06_000010_add_cancelled_reservation_status.php');
        $migration->down();
        $before = $reservation->fresh()->getRawOriginal();
        $migration->up();
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
        $this->actingAs($resident)->post($this->path($reservation), ['reason' => 'No longer needed'])->assertSessionHas('status');
        try {
            $migration->down();
            $this->fail('Cancellation history must prevent rollback');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cancellation records exist', $exception->getMessage());
        }
        $this->assertSame(Status::Cancelled, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_histories', 1);
    }

    private function user(UserRole $role = UserRole::Resident): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }

    private function reservation(User $resident, Status $status = Status::Pending): Reservation
    {
        $service = Service::first() ?? Service::create(['name' => 'Clearance']);
        $schedule = Schedule::first() ?? Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5]);

        return $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
    }

    private function path(Reservation $reservation): string
    {
        return route('resident.reservations.cancel', $reservation);
    }
}
