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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminReservationProcessingTest extends TestCase
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

    public static function endpoints(): array
    {
        return [['GET', '/admin/reservations'], ['GET', '/admin/reservations/1'], ['PATCH', '/admin/reservations/1/status']];
    }

    #[DataProvider('endpoints')]
    public function test_access_requires_active_verified_admin(string $method, string $path): void
    {
        $reservation = $this->reservation();
        $this->call($method, $path, $this->data())->assertRedirect(route('login'));
        $resident = $this->user(UserRole::Resident);
        $this->actingAs($resident)->call($method, $path, $this->data())->assertForbidden();
        $resident->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($resident)->call($method, $path, $this->data())->assertForbidden();
        $admin = $this->user();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->call($method, $path, $this->data())->assertRedirect(route('verification.notice'));
        $admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($admin)->call($method, $path, $this->data())->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(Status::Pending, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public function test_admin_list_search_status_combination_and_empty_states(): void
    {
        $this->actingAs($this->user())->get('/admin/reservations')->assertOk()->assertSee('No reservations yet');
        $alice = $this->reservation('Certificate of Residency', 'Alice Calayo');
        $bob = $this->reservation('Barangay Clearance', 'Bob Calayo', Status::UnderReview);
        $this->get('/admin/reservations')->assertOk()->assertSee('Alice Calayo')->assertSee('Bob Calayo')->assertSee('Nov 1, 2026')->assertSee('9:00 AM')->assertSee('Submitted');
        $this->get('/admin/reservations?search=Alice')->assertSee('Certificate of Residency')->assertDontSee('Bob Calayo');
        $this->get('/admin/reservations?search=Clearance')->assertSee('Bob Calayo')->assertDontSee('Alice Calayo');
        $this->get('/admin/reservations?search=0')->assertSee('No matching reservations')->assertDontSee('Alice Calayo')->assertDontSee('Bob Calayo');
        $this->get('/admin/reservations?status=pending')->assertSee('Alice Calayo')->assertDontSee('Bob Calayo');
        $this->get('/admin/reservations?search=Clearance&status=pending')->assertSee('No matching reservations')->assertDontSee('Bob Calayo');
        $this->get('/admin/reservations?search=%27%20OR%201%3D1')->assertSee('No matching reservations');
        $this->get('/admin/reservations?status=cancelled')->assertRedirect(route('admin.reservations.index'))->assertSessionHasErrors('status');
        $this->get('/admin/reservations?search[]=bad')->assertRedirect(route('admin.reservations.index'))->assertSessionHasErrors('search');
        $this->assertSame(Status::Pending, $alice->fresh()->status);
        $this->assertSame(Status::UnderReview, $bob->fresh()->status);
    }

    public function test_pagination_retains_filters_and_eager_loads_related_data(): void
    {
        $this->actingAs($this->user());
        for ($i = 1; $i <= 11; $i++) {
            $this->reservation(sprintf('Document %02d', $i));
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/admin/reservations?search=Document&status=pending')->assertOk()->assertSee('Showing 1–10 of 11 reservations')
            ->assertSee('Document 11')->assertDontSee('Document 01')->assertSee('search=Document', false)->assertSee('status=pending', false);
        $this->assertLessThanOrEqual(5, count(array_filter(DB::getQueryLog(), fn ($query) => str_starts_with(strtolower($query['query']), 'select'))));
        DB::disableQueryLog();
        $this->get('/admin/reservations?search=Document&status=pending&page=2')->assertOk()->assertSee('Document 01')->assertDontSee('Document 11');
    }

    public function test_details_show_contacts_requirements_and_history_without_credentials_or_uploads(): void
    {
        $reservation = $this->reservation();
        $reservation->service->serviceRequirements()->create(['name' => 'Valid ID', 'description' => '<script>bad()</script>']);
        $this->actingAs($this->user())->get('/admin/reservations/1')->assertOk()->assertSee('Valid ID')->assertSee('Resident Example')
            ->assertSee($reservation->user->email)->assertSee('Sunday, November 1, 2026')->assertSee('Under Review')
            ->assertSee('No status changes recorded yet')->assertDontSee($reservation->user->password, false)->assertDontSee('type="file"', false)
            ->assertDontSee('<script>bad()</script>', false)->assertDontSee('value="approved"', false)->assertDontSee('value="cancelled"', false);
        $this->get('/admin/reservations/999')->assertNotFound();
        $this->patch('/admin/reservations/999/status', $this->data())->assertNotFound();
    }

    public function test_complete_workflow_records_exact_actor_previous_status_timestamp_and_preserves_data(): void
    {
        $reservation = $this->reservation();
        $admin = $this->user();
        $this->actingAs($admin);
        $previous = Status::Pending;
        foreach ([Status::UnderReview, Status::Approved, Status::ReadyForPickup, Status::Completed] as $index => $next) {
            $this->patch('/admin/reservations/1/status', ['status' => $next->value, 'expected_status' => $previous->value,
                'notes' => 'Checked '.$index, 'changed_by' => $reservation->user_id, 'user_id' => $admin->id, 'service_id' => 999])
                ->assertRedirect(route('admin.reservations.show', $reservation))->assertSessionHas('status');
            $history = $reservation->statusHistories()->latest('id')->firstOrFail();
            $this->assertSame($previous, $history->from_status);
            $this->assertSame($next, $history->to_status);
            $this->assertSame($admin->id, $history->changed_by);
            $this->assertNotNull($history->changed_at);
            $this->assertSame('Checked '.$index, $history->notes);
            $previous = $next;
        }
        $this->assertSame(Status::Completed, $reservation->fresh()->status);
        $this->assertSame($reservation->user_id, $reservation->fresh()->user_id);
        $this->assertSame($reservation->service_id, $reservation->fresh()->service_id);
        $this->assertDatabaseCount('reservation_status_histories', 4);
        $this->get('/admin/reservations/1')->assertOk()->assertSee('Checked 0')->assertSee('Checked 3')->assertSee($admin->name)->assertDontSee('id="next-status"', false);
        $this->actingAs($reservation->user)->get('/resident/reservations/1')->assertOk()->assertSee('Completed')->assertDontSee('Checked 0');
        foreach (['qr_tickets', 'checkin_logs', 'reservation_attachments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_all_other_transition_pairs_are_rejected_without_history(): void
    {
        $this->actingAs($this->user());
        $allowed = ['pending' => ['under_review', 'rejected'], 'under_review' => ['approved', 'rejected'], 'approved' => ['ready_for_pickup'], 'ready_for_pickup' => ['completed'], 'completed' => [], 'rejected' => []];
        foreach (Status::cases() as $from) {
            foreach (Status::cases() as $to) {
                if (in_array($to->value, $allowed[$from->value], true)) {
                    continue;
                }
                $reservation = $this->reservation('Test '.$from->value, 'Resident Example', $from);
                $this->patch(route('admin.reservations.status', $reservation), ['status' => $to->value, 'expected_status' => $from->value, 'notes' => 'Test reason'])->assertSessionHasErrors('status');
                $this->assertSame($from, $reservation->fresh()->status);
            }
        }
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public function test_rejection_requires_reason_and_is_terminal_from_both_review_states(): void
    {
        $this->actingAs($this->user());
        foreach ([Status::Pending, Status::UnderReview] as $from) {
            $reservation = $this->reservation('Rejectable', 'Resident Example', $from);
            $this->patch(route('admin.reservations.status', $reservation), ['status' => 'rejected', 'expected_status' => $from->value, 'notes' => '  '])->assertSessionHasErrors('notes');
            $this->assertSame($from, $reservation->fresh()->status);
            $this->patch(route('admin.reservations.status', $reservation), ['status' => 'rejected', 'expected_status' => $from->value, 'notes' => 'Missing information'])->assertSessionHas('status');
            $this->assertSame(Status::Rejected, $reservation->fresh()->status);
            $this->assertSame('Missing information', $reservation->statusHistories()->sole()->notes);
        }
        $this->assertDatabaseCount('reservation_status_histories', 2);
    }

    public function test_stale_forms_and_replayed_updates_do_not_overwrite_or_duplicate_history(): void
    {
        $reservation = $this->reservation();
        $this->actingAs($this->user())->patch('/admin/reservations/1/status', $this->data())->assertSessionHas('status');
        $this->patch('/admin/reservations/1/status', $this->data())->assertSessionHasErrors('status');
        $this->patch('/admin/reservations/1/status', ['status' => 'approved', 'expected_status' => 'pending'])->assertSessionHasErrors('status');
        $this->assertSame(Status::UnderReview, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_histories', 1);
    }

    public function test_invalid_payloads_csrf_and_no_delete_endpoint(): void
    {
        $reservation = $this->reservation();
        $this->actingAs($this->user());
        foreach ([[], ['status' => 'cancelled', 'expected_status' => 'pending'], ['status' => 'under_review'], ['status' => ['approved'], 'expected_status' => 'pending'], [...$this->data(), 'notes' => str_repeat('x', 2001)]] as $data) {
            $this->patch('/admin/reservations/1/status', $data)->assertSessionHasErrors();
        }
        $this->delete('/admin/reservations/1')->assertStatus(405);
        $this->app['env'] = 'local';
        $this->patch('/admin/reservations/1/status', $this->data())->assertStatus(419);
        $this->assertSame(Status::Pending, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public function test_history_write_failure_rolls_back_the_status_update(): void
    {
        $reservation = $this->reservation();
        DB::statement("CREATE TRIGGER fail_history BEFORE INSERT ON reservation_status_histories BEGIN SELECT RAISE(ABORT, 'Simulated history failure'); END");
        $this->actingAs($this->user())->withoutExceptionHandling();
        try {
            $this->patch('/admin/reservations/1/status', $this->data());
            $this->fail('Expected history failure');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Simulated history failure', $exception->getMessage());
        }
        $this->assertSame(Status::Pending, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    private function data(): array
    {
        return ['status' => 'under_review', 'expected_status' => 'pending'];
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }

    private function reservation(string $serviceName = 'Clearance', string $name = 'Resident Example', Status $status = Status::Pending): Reservation
    {
        $resident = $this->user(UserRole::Resident);
        $resident->update(['name' => $name]);
        $service = Service::create(['name' => $serviceName]);
        $schedule = Schedule::first() ?? Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5]);

        return $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
    }
}
