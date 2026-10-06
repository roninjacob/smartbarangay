<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus as Status;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResidentRequestStatusTest extends TestCase
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

    public static function pages(): array
    {
        return [['/resident/request-status'], ['/resident/request-status/1']];
    }

    #[DataProvider('pages')]
    public function test_access_requires_active_verified_resident(string $path): void
    {
        $this->get($path)->assertRedirect(route('login'));
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->get($path)->assertForbidden();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->get($path)->assertForbidden();
        $resident = $this->user();
        $resident->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($resident)->get($path)->assertRedirect(route('verification.notice'));
        $resident->forceFill(['is_active' => false, 'email_verified_at' => now()])->save();
        $this->actingAs($resident)->get($path)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_list_is_owner_scoped_paginates_and_uses_bounded_queries(): void
    {
        $resident = $this->user();
        $other = $this->user();
        $this->reservation($other, 'FOREIGN PRIVATE SERVICE');
        for ($i = 1; $i <= 11; $i++) {
            $reservation = $this->reservation($resident, sprintf('My Service %02d', $i));
            $reservation->forceFill(['updated_at' => now()->addMinutes($i)])->save();
        }
        $this->actingAs($resident);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/resident/request-status?user_id='.$other->id)->assertOk()->assertSee('Showing 1–10 of 11 requests')
            ->assertSee('My Service 11')->assertDontSee('My Service 01')->assertDontSee('FOREIGN PRIVATE SERVICE')
            ->assertSee('Latest update')->assertSee('Submitted')->assertSee('Nov 1, 2026')->assertSee('9:00 AM')->assertSee('10:00 AM')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertLessThanOrEqual(4, count(array_filter(DB::getQueryLog(), fn ($query) => str_starts_with(strtolower($query['query']), 'select'))));
        DB::disableQueryLog();
        $this->get('/resident/request-status?page=2')->assertSee('My Service 01')->assertDontSee('My Service 11');
        $this->get('/resident/request-status?page=99')->assertSee('No requests on this page');
    }

    public function test_foreign_and_missing_requests_return_404_before_related_information_is_loaded(): void
    {
        $other = $this->user();
        $foreign = $this->reservation($other, 'FOREIGN PRIVATE SERVICE');
        $foreign->statusHistories()->create(['from_status' => 'pending', 'to_status' => 'under_review', 'changed_at' => now(), 'notes' => 'PRIVATE ADMIN NOTE']);
        $this->actingAs($this->user());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/resident/request-status/'.$foreign->id.'?user_id='.$other->id)->assertNotFound()->assertDontSee('FOREIGN PRIVATE SERVICE')->assertDontSee('PRIVATE ADMIN NOTE');
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('reservation_status_histories', $query['query']);
        }
        DB::disableQueryLog();
        $this->get('/resident/request-status/9999')->assertNotFound();
    }

    public static function statuses(): array
    {
        return array_map(fn ($status) => [$status], Status::cases());
    }

    #[DataProvider('statuses')]
    public function test_each_status_has_clear_message_and_only_pending_offers_cancellation(Status $status): void
    {
        $resident = $this->user();
        $reservation = $this->reservation($resident, 'Clearance', $status);
        $this->actingAs($resident)->get('/resident/request-status')->assertSee($status->label())->assertSee($status->residentMessage());
        $response = $this->get('/resident/request-status/'.$reservation->id)->assertOk()->assertSee($status->label())->assertSee($status->residentMessage())
            ->assertSee('Request timeline')->assertSee('Reservation submitted')->assertSee('No status changes recorded yet')
            ->assertDontSee('method="POST" action="http://localhost/resident/request-status', false);
        if ($status->canBeCancelledByResident()) {
            $response->assertSee('Cancel reservation');
        } else {
            $response->assertDontSee('Cancel reservation');
        }
        if (in_array($status, [Status::Rejected, Status::Cancelled], true)) {
            $response->assertDontSee('aria-current="step"', false);
        } else {
            $response->assertSee('aria-current="step"', false)->assertSee('Current status');
        }
        $this->assertSame($status, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public function test_timeline_is_chronological_and_omits_internal_notes_and_actor_credentials(): void
    {
        $resident = $this->user();
        $admin = $this->user(UserRole::Admin);
        $admin->update(['name' => 'PRIVATE ADMIN NAME']);
        $reservation = $this->reservation($resident, '<script>bad()</script>', Status::Completed);
        $statuses = [Status::Pending, Status::UnderReview, Status::Approved, Status::ReadyForPickup, Status::Completed];
        for ($i = 1; $i < count($statuses); $i++) {
            $reservation->statusHistories()->create(['from_status' => $statuses[$i - 1], 'to_status' => $statuses[$i], 'changed_at' => '2026-10-06 0'.$i.':00:00', 'changed_by' => $admin->id, 'notes' => 'PRIVATE ADMIN NOTE '.$i]);
        }
        $this->actingAs($resident)->get('/resident/request-status/'.$reservation->id)->assertOk()
            ->assertSeeInOrder(['Oct 6, 2026, 9:00 AM', 'Oct 6, 2026, 10:00 AM', 'Oct 6, 2026, 11:00 AM', 'Oct 6, 2026, 12:00 PM'])
            ->assertSee('changed to')->assertDontSee('PRIVATE ADMIN NOTE')->assertDontSee('PRIVATE ADMIN NAME')
            ->assertDontSee($admin->email)->assertDontSee($admin->password, false)->assertDontSee('<script>bad()</script>', false)
            ->assertViewHas('histories', fn ($histories) => $histories->every(fn ($history) => ! array_key_exists('notes', $history->getAttributes()) && ! array_key_exists('changed_by', $history->getAttributes())));
        $this->assertDatabaseCount('reservation_status_histories', 4);
    }

    public function test_timeline_pagination_keeps_all_records_and_initial_event_on_first_page_only(): void
    {
        $resident = $this->user();
        $reservation = $this->reservation($resident);
        for ($i = 1; $i <= 11; $i++) {
            $reservation->statusHistories()->create(['from_status' => 'pending', 'to_status' => 'under_review', 'changed_at' => '2026-10-06 01:'.sprintf('%02d', $i).':00']);
        }
        $this->actingAs($resident)->get('/resident/request-status/'.$reservation->id)->assertSee('Reservation submitted')->assertSee('9:10 AM')->assertDontSee('9:11 AM');
        $this->get('/resident/request-status/'.$reservation->id.'?page=2')->assertSee('9:11 AM')->assertDontSee('Reservation submitted');
        $this->get('/resident/request-status/'.$reservation->id.'?page=99')->assertSee('No timeline entries on this page');
        $this->assertDatabaseCount('reservation_status_histories', 11);
    }

    public function test_admin_updates_appear_in_tracking_without_exposing_notes(): void
    {
        $resident = $this->user();
        $reservation = $this->reservation($resident);
        $this->actingAs($this->user(UserRole::Admin))->patch('/admin/reservations/'.$reservation->id.'/status', ['status' => 'under_review', 'expected_status' => 'pending', 'notes' => 'PRIVATE PROCESSING NOTE'])->assertSessionHas('status');
        $this->actingAs($resident)->get('/resident/request-status/'.$reservation->id)->assertSee('Under Review')->assertSee(Status::UnderReview->residentMessage())->assertDontSee('PRIVATE PROCESSING NOTE');
        $this->get('/resident/reservations/'.$reservation->id)->assertSee(route('resident.request-status.show', $reservation), false);
        $this->assertDatabaseCount('reservation_status_histories', 1);
    }

    public function test_empty_state_navigation_and_readonly_routes_preserve_csrf_and_data(): void
    {
        $resident = $this->user();
        $this->actingAs($resident)->get('/resident/request-status')->assertSee('No requests to track yet')->assertSee('New Reservation');
        $reservation = $this->reservation($resident);
        $this->get('/resident/home')->assertSee(route('resident.request-status.index'), false);
        $before = $reservation->fresh()->getRawOriginal();
        $this->post('/resident/request-status', ['status' => 'cancelled'])->assertStatus(405);
        $this->patch('/resident/request-status/'.$reservation->id, ['status' => 'cancelled'])->assertStatus(405);
        $this->delete('/resident/request-status/'.$reservation->id)->assertStatus(405);
        $this->post('/resident/reservations/'.$reservation->id.'/cancel')->assertSessionHasErrors('reason');
        $this->app['env'] = 'local';
        $this->actingAs($this->user(UserRole::Admin))->patch('/admin/reservations/'.$reservation->id.'/status', ['status' => 'under_review', 'expected_status' => 'pending'])->assertStatus(419);
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $this->assertDatabaseCount('qr_tickets', 0);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    private function user(UserRole $role = UserRole::Resident): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }

    private function reservation(User $resident, string $name = 'Clearance', Status $status = Status::Pending): Reservation
    {
        $service = Service::create(['name' => $name]);
        $schedule = Schedule::first() ?? Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5]);

        return $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
    }
}
