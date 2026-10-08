<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.foreign_key_constraints' => true,
            'session.driver' => 'array',
            'cache.default' => 'array',
            'mail.default' => 'array',
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        $this->travelBack();
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public static function roles(): array
    {
        return [
            'resident' => [UserRole::Resident, '/resident/home', 'Resident'],
            'admin' => [UserRole::Admin, '/admin/home', 'Admin'],
        ];
    }

    #[DataProvider('roles')]
    public function test_authenticated_dashboards_render_shared_shell_and_zero_states(UserRole $role, string $path, string $label): void
    {
        $user = $this->user($role);
        $this->actingAs($user)->get($path)->assertOk()
            ->assertViewIs('dashboards.'.strtolower($label))
            ->assertViewHas('totalReservations', 0)
            ->assertSee($label.' Area')->assertSee('Dashboard')->assertSee($user->name)
            ->assertSee('Barangay Calayo')->assertSee('Nasugbu, Batangas')
            ->assertSee('No reservations yet')->assertSee('Profile / Account Settings')
            ->assertSee('aria-current="page"', false)
            ->assertSee('data-bs-target="#app-sidebar"', false)
            ->assertSee('method="POST"', false)->assertSee('name="_token"', false)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[DataProvider('roles')]
    public function test_dashboard_routes_preserve_guest_role_and_inactive_protection(UserRole $role, string $path, string $label): void
    {
        $this->get($path)->assertRedirect(route('login'));
        $other = $this->user($role === UserRole::Resident ? UserRole::Admin : UserRole::Resident);
        $this->actingAs($other)->get($path)->assertForbidden()->assertDontSee('Recent reservations');
        $inactive = $this->user($role);
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive)->get($path)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_both_roles_require_verification_before_dashboard_access(): void
    {
        $resident = $this->user(UserRole::Resident);
        $resident->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($resident)->get('/resident/home')->assertRedirect(route('verification.notice'));
        $admin = $this->user(UserRole::Admin);
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->get('/admin/home')->assertRedirect(route('verification.notice'));
    }

    public function test_resident_counts_and_recent_rows_never_include_another_residents_records(): void
    {
        $resident = $this->user(UserRole::Resident);
        $other = $this->user(UserRole::Resident);
        foreach (ReservationStatus::cases() as $status) {
            $this->reservation($resident, $status, 'My '.$status->label());
        }
        $this->reservation($resident, ReservationStatus::Pending, 'My newest request');
        for ($i = 0; $i < 8; $i++) {
            $this->reservation($other, ReservationStatus::ReadyForPickup, 'Private other resident service '.$i);
        }

        $response = $this->actingAs($resident)->get('/resident/home')->assertOk()
            ->assertViewHas('totalReservations', count(ReservationStatus::cases()) + 1)->assertViewHas('inProgress', 4)
            ->assertViewHas('counts', fn ($counts) => $counts['pending'] === 2
                && $counts['ready_for_pickup'] === 1 && $counts['completed'] === 1 && $counts['rejected'] === 1)
            ->assertViewHas('recentReservations', fn ($rows) => $rows->count() === 6
                && $rows->every(fn ($row) => $row->user_id === $resident->id))
            ->assertSee('My newest request')->assertSee('You have documents ready for pickup.')
            ->assertDontSee('Private other resident service')->assertDontSee($other->email);
        $this->assertSame('My newest request', $response->viewData('recentReservations')->first()->service->name);
    }

    public function test_admin_aggregates_statuses_residents_and_appointments_in_philippine_time(): void
    {
        // UTC is still yesterday when the Philippine appointment day begins.
        $this->travelTo(Carbon::parse('2026-10-06 16:30:00', 'UTC'));
        $admin = $this->user(UserRole::Admin);
        $resident = $this->user(UserRole::Resident);
        $inactiveResident = $this->user(UserRole::Resident);
        $inactiveResident->forceFill(['is_active' => false, 'email_verified_at' => null])->save();
        foreach (ReservationStatus::cases() as $status) {
            $this->reservation($resident, $status, $status->label().' service', '2026-10-07');
        }
        $this->reservation($inactiveResident, ReservationStatus::Pending, 'Yesterday service', '2026-10-06');
        $this->reservation($resident, ReservationStatus::Approved, 'Tomorrow service', '2026-10-08');

        $this->actingAs($admin)->get('/admin/home')->assertOk()
            ->assertViewHas('totalResidents', 2)->assertViewHas('totalReservations', count(ReservationStatus::cases()) + 2)
            ->assertViewHas('needsAttention', 3)->assertViewHas('todayReservations', count(ReservationStatus::cases()))
            ->assertViewHas('counts', fn ($counts) => $counts['pending'] === 2
                && $counts['under_review'] === 1 && $counts['approved'] === 2
                && $counts['ready_for_pickup'] === 1 && $counts['completed'] === 1 && $counts['rejected'] === 1)
            ->assertViewHas('dashboardDate', fn ($date) => $date->toDateString() === '2026-10-07')
            ->assertViewHas('recentReservations', fn ($rows) => $rows->count() === 6)
            ->assertSee('Tomorrow service');
    }

    public function test_recent_rows_show_status_and_schedule_without_private_processing_details(): void
    {
        $resident = $this->user(UserRole::Resident);
        $reservation = $this->reservation($resident, ReservationStatus::UnderReview, 'Barangay Clearance');
        $reservation->update(['purpose' => 'Private purpose text', 'admin_notes' => 'Internal processing note']);
        $this->actingAs($resident)->get('/resident/home')->assertOk()
            ->assertSee('Barangay Clearance')->assertSee('Under Review')->assertSee('09:00–10:00')
            ->assertDontSee('Private purpose text')->assertDontSee('Internal processing note');
    }

    #[DataProvider('roles')]
    public function test_queries_stay_bounded_and_recent_relationships_are_eager_loaded(UserRole $role, string $path, string $label): void
    {
        $viewer = $this->user($role);
        $resident = $role === UserRole::Resident ? $viewer : $this->user(UserRole::Resident);
        for ($i = 0; $i < 9; $i++) {
            $this->reservation($resident, ReservationStatus::Pending, 'Document '.$i);
        }
        Model::preventLazyLoading();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($viewer)->get($path)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($role === UserRole::Resident ? 4 : 7, count($queries));
    }

    #[DataProvider('roles')]
    public function test_navigation_phase_boundaries_and_read_only_account_summary(UserRole $role, string $path, string $label): void
    {
        $user = $this->user($role);
        $response = $this->actingAs($user)->get($path)->assertOk()
            ->assertSee('Profile overview')->assertSee($user->email)
            ->assertSee('Manage your profile and optional picture')
            ->assertDontSee('href="#"', false)->assertDontSee('name="role"', false)
            ->assertDontSee('name="password"', false);
        if ($role === UserRole::Admin) {
            $response->assertSee('aria-disabled="true"', false)->assertSee('Soon');
        } else {
            $response->assertDontSee('aria-disabled="true"', false)
                ->assertSee(route('resident.qr-tickets.index'), false);
        }
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//*[@aria-disabled="true"]//a')->length);
        $this->assertSame(1, $xpath->query('//form[@action="'.route('logout').'"][@method="POST"]')->length);
    }

    public function test_long_names_and_service_text_are_escaped(): void
    {
        $resident = $this->user(UserRole::Resident);
        $resident->update(['name' => '<script>alert(1)</script>'.str_repeat('LongName', 20)]);
        $this->reservation($resident, ReservationStatus::Approved, '<script>service()</script>');
        $this->actingAs($resident)->get('/resident/home')->assertOk()
            ->assertSee($resident->name)->assertSee('<script>service()</script>')
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<script>service()</script>', false);
    }

    private function user(UserRole $role): User
    {
        $user = User::factory()->make();
        $user->role = $role;
        $user->is_active = true;
        $user->save();

        return $user->refresh();
    }

    private function reservation(User $resident, ReservationStatus $status, string $serviceName, ?string $date = null): Reservation
    {
        $service = Service::create(['name' => $serviceName]);
        $date ??= '2026-10-12';
        $schedule = Schedule::whereDate('date', $date)->where('start_time', '09:00:00')
            ->where('end_time', '10:00:00')->first() ?? Schedule::create([
                'date' => $date, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 20,
            ]);

        return Reservation::create([
            'user_id' => $resident->id, 'service_id' => $service->id,
            'schedule_id' => $schedule->id, 'status' => $status,
        ])->refresh();
    }
}
