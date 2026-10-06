<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResidentReservationViewingTest extends TestCase
{
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
        Model::preventLazyLoading(false);
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public static function pages(): array
    {
        return [['/resident/reservations'], ['/resident/reservations/1']];
    }

    #[DataProvider('pages')]
    public function test_pages_require_authenticated_active_verified_resident(string $path): void
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

    public function test_resident_sees_only_own_requests_even_with_manipulated_query_parameters(): void
    {
        $resident = $this->user();
        $other = $this->user();
        $own = $this->reservation($resident, 'My Clearance');
        $this->reservation($other, 'Private foreign service');
        $response = $this->actingAs($resident)->get('/resident/reservations?user_id='.$other->id.'&status=approved')->assertOk()
            ->assertViewIs('resident.reservations.index')->assertSee('My Clearance')->assertDontSee('Private foreign service')
            ->assertSee('Nov 1, 2026')->assertSee('9:00 AM')->assertSee('10:00 AM')->assertSee('Pending')
            ->assertSee('Submitted')->assertSee(route('resident.reservations.show', $own), false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(1, $response->viewData('reservations')->total());
        $this->assertSame(1, substr_count($response->getContent(), 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/href="[^"]*\/resident\/reservations" class="[^"]*is-current/', $response->getContent());
    }

    public function test_empty_state_links_to_creation_and_dashboard_links_to_list(): void
    {
        $this->actingAs($this->user())->get('/resident/reservations')->assertOk()->assertSee('No reservations yet')
            ->assertSee('Create your first reservation')->assertSee(route('resident.reservations.create'), false);
        $this->get('/resident/home')->assertOk()->assertSee(route('resident.reservations.index'), false);
        $response = $this->get('/resident/reservations/create')->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/href="[^"]*\/resident\/reservations\/create" class="[^"]*is-current/', $response->getContent());
    }

    public function test_list_is_paginated_newest_first_with_deterministic_order_and_no_n_plus_one(): void
    {
        $resident = $this->user();
        for ($i = 1; $i <= 11; $i++) {
            $this->reservation($resident, 'Own document '.sprintf('%02d', $i));
        }
        $this->reservation($this->user(), 'Foreign newest record');
        Model::preventLazyLoading();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($resident)->get('/resident/reservations')->assertOk()->assertSee('Showing 1–10 of 11 reservations')
            ->assertSeeInOrder(['Own document 11', 'Own document 10', 'Own document 02'])->assertDontSee('Own document 01')
            ->assertDontSee('Foreign newest record');
        $queries = array_filter(DB::getQueryLog(), fn ($query) => str_starts_with(strtolower($query['query']), 'select'));
        $this->assertLessThanOrEqual(4, count($queries));
        DB::disableQueryLog();
        $this->get('/resident/reservations?page=2')->assertOk()->assertSee('Showing 11–11 of 11 reservations')
            ->assertSee('Own document 01')->assertDontSee('Own document 11')->assertDontSee('Foreign newest record');
        $this->get('/resident/reservations?page=99')->assertOk()->assertSee('No reservations on this page')
            ->assertSee('Back to first page')->assertDontSee('No reservations yet');
    }

    public function test_details_show_service_schedule_status_and_readonly_current_requirements(): void
    {
        $resident = $this->user();
        $reservation = $this->reservation($resident, 'Barangay Clearance');
        $reservation->service->update(['description' => 'For employment and official transactions.', 'is_active' => false]);
        $reservation->schedule->update(['is_active' => false]);
        $reservation->service->serviceRequirements()->create(['name' => 'Valid ID', 'description' => 'Bring an original copy.']);
        $reservation->service->serviceRequirements()->create(['name' => 'Supporting document', 'is_required' => false]);
        $before = $reservation->fresh()->getRawOriginal();
        Model::preventLazyLoading();
        $response = $this->actingAs($resident)->get(route('resident.reservations.show', $reservation))->assertOk()
            ->assertViewIs('resident.reservations.show')->assertSee('Barangay Clearance')->assertSee('For employment and official transactions.')
            ->assertSee('Sunday, November 1, 2026')->assertSee('9:00 AM')->assertSee('10:00 AM')->assertSee('Pending')
            ->assertSee('Submitted')->assertSee('Last updated')->assertSee('Valid ID')->assertSee('Bring an original copy.')
            ->assertSee('Required')->assertSee('Optional')->assertSee('Back to My Reservations')
            ->assertDontSee('Private admin note')->assertDontSee('type="file"', false)->assertDontSee('Approve Reservation')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(1, substr_count($response->getContent(), 'aria-current="page"'));
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
        $reservation->service->serviceRequirements()->delete();
        $this->get(route('resident.reservations.show', $reservation))->assertOk()->assertSee('No requirements have been listed');
        foreach (['reservation_attachments', 'qr_tickets', 'checkin_logs', 'reservation_status_histories'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_foreign_missing_and_malformed_details_return_404_without_loading_private_relations(): void
    {
        $private = $this->reservation($this->user(), 'Confidential foreign service');
        $private->service->serviceRequirements()->create(['name' => 'Confidential requirement']);
        $this->actingAs($this->user());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('resident.reservations.show', $private))->assertNotFound()
            ->assertDontSee('Confidential foreign service')->assertDontSee('Confidential requirement')->assertDontSee('November 1, 2026');
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/from ["`]?(services|schedules|service_requirements)["`]?/i', $query['query']);
        }
        DB::disableQueryLog();
        $this->get('/resident/reservations/999999')->assertNotFound();
        $this->get('/resident/reservations/not-a-number')->assertNotFound();
    }

    public function test_all_existing_statuses_render_and_untrusted_text_is_escaped(): void
    {
        $resident = $this->user();
        $this->actingAs($resident);
        foreach (ReservationStatus::cases() as $status) {
            $reservation = $this->reservation($resident, $status->label().' service', $status);
            $this->get(route('resident.reservations.show', $reservation))->assertOk()->assertSee($status->label());
        }
        $this->get('/resident/reservations')->assertOk()->assertSee('Under Review')->assertSee('Ready for Pickup')->assertSee('Rejected');
        $reservation->service->update(['name' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(1)>']);
        $reservation->service->serviceRequirements()->create(['name' => '<script>alert(2)</script>']);
        $this->get(route('resident.reservations.show', $reservation))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x onerror=alert(1)>', false);
        $this->get('/resident/reservations')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_csrf_remains_active_and_details_offer_no_mutation_endpoints(): void
    {
        $resident = $this->user();
        $reservation = $this->reservation($resident, 'Clearance');
        $this->actingAs($resident);
        $this->app['env'] = 'local';
        $this->get('/resident/reservations')->assertOk()->assertSee('name="_token"', false);
        $this->get(route('resident.reservations.show', $reservation))->assertOk()->assertSee('name="_token"', false);
        $this->post('/resident/reservations', ['status' => 'approved'])->assertStatus(419);
        $this->post('/logout')->assertStatus(419);
        $this->patch(route('resident.reservations.show', $reservation), ['status' => 'approved'])->assertStatus(405);
        $this->delete(route('resident.reservations.show', $reservation))->assertStatus(405);
        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservations', 1);
    }

    private function user(UserRole $role = UserRole::Resident): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }

    private function reservation(User $resident, string $name, ReservationStatus $status = ReservationStatus::Pending): Reservation
    {
        $service = Service::create(['name' => $name]);
        $schedule = Schedule::first() ?? Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5]);

        $reservation = $resident->reservations()->make([
            'service_id' => $service->id, 'schedule_id' => $schedule->id,
            'status' => $status, 'admin_notes' => 'Private admin note',
        ]);
        $reservation->forceFill(['created_at' => '2026-10-06 02:00:00', 'updated_at' => '2026-10-06 03:00:00'])->save();

        return $reservation;
    }
}
