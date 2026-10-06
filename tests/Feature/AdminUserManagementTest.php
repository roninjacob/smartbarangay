<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
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
        return [['GET', '/admin/users'], ['GET', '/admin/users/1'], ['PATCH', '/admin/users/1/status']];
    }

    #[DataProvider('endpoints')]
    public function test_only_active_verified_admin_can_access(string $method, string $path): void
    {
        $resident = $this->user();
        $data = ['is_active' => 0, 'expected_is_active' => 1];
        $this->call($method, $path, $data)->assertRedirect(route('login'));
        $this->actingAs($resident)->call($method, $path, $data)->assertForbidden();
        $resident->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($resident)->call($method, $path, $data)->assertForbidden();
        $admin = $this->user(UserRole::Admin, ['email_verified_at' => null]);
        $this->actingAs($admin)->call($method, $path, $data)->assertRedirect(route('verification.notice'));
        $admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($admin)->call($method, $path, $data)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue($resident->fresh()->is_active);
    }

    public function test_resident_only_listing_search_filters_and_empty_states(): void
    {
        $admin = $this->user(UserRole::Admin, ['name' => 'Private Admin', 'email' => 'admin-secret@example.test']);
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('No Resident accounts yet')->assertDontSee('View account');
        $otherAdmin = $this->user(UserRole::Admin, ['email' => 'other-admin@example.test']);
        $alice = $this->user(UserRole::Resident, ['name' => 'Alice Calayo', 'email' => 'alice@example.test', 'contact_number' => '09171112222']);
        $bob = $this->user(UserRole::Resident, ['name' => 'Bob Calayo', 'email' => 'bob@example.test', 'contact_number' => '09283334444', 'is_active' => false, 'email_verified_at' => null]);
        $this->get('/admin/users')->assertOk()->assertSee('Alice Calayo')->assertSee('Bob Calayo')->assertSee('09171112222')->assertSee('Registered')->assertDontSee('other-admin@example.test')
            ->assertViewHas('users', fn ($users) => ! $users->pluck('id')->contains($admin->id) && ! $users->pluck('id')->contains($otherAdmin->id));
        foreach (['Alice', 'alice@example.test', '0917111'] as $search) {
            $this->get('/admin/users?search='.urlencode($search))->assertSee('Alice Calayo')->assertDontSee('Bob Calayo');
        }
        $this->get('/admin/users?status=inactive&verification=unverified')->assertSee('Bob Calayo')->assertDontSee('Alice Calayo');
        $this->get('/admin/users?status=active&verification=verified')->assertSee('Alice Calayo')->assertDontSee('Bob Calayo');
        $this->get('/admin/users?search=bob&status=active')->assertSee('No matching residents')->assertDontSee('Bob Calayo');
        $this->get('/admin/users?search=admin-secret')->assertSee('No matching residents')->assertViewHas('users', fn ($users) => $users->isEmpty());
        $this->get('/admin/users?search=%27%20OR%201%3D1')->assertSee('No matching residents');
        $this->get('/admin/users?search=0&status=inactive')->assertSee('Bob Calayo')->assertDontSee('Alice Calayo');
        $this->assertTrue($alice->fresh()->is_active);
        $this->assertFalse($bob->fresh()->is_active);
    }

    public function test_pagination_preserves_filters_without_relationship_queries_or_credentials(): void
    {
        $this->actingAs($this->user(UserRole::Admin));
        for ($i = 1; $i <= 11; $i++) {
            $this->user(UserRole::Resident, ['name' => sprintf('Resident %02d', $i), 'contact_number' => null]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/admin/users?search=Resident&status=active&verification=verified')->assertOk()
            ->assertSee('Showing 1–10 of 11 residents')->assertSee('Resident 11')->assertDontSee('Resident 01')
            ->assertSee('search=Resident', false)->assertSee('status=active', false)->assertSee('verification=verified', false);
        $selects = array_filter(DB::getQueryLog(), fn ($query) => str_starts_with(strtolower($query['query']), 'select'));
        $this->assertLessThanOrEqual(2, count($selects));
        $this->assertStringNotContainsString('password', implode(' ', array_column($selects, 'query')));
        DB::disableQueryLog();
        $this->get('/admin/users?search=Resident&status=active&verification=verified&page=2')->assertSee('Resident 01')->assertDontSee('Resident 11');
    }

    public function test_detail_is_readonly_and_never_exposes_credentials(): void
    {
        $resident = $this->user(UserRole::Resident, ['name' => '<script>bad()</script>', 'contact_number' => '09171234567', 'remember_token' => 'SECRET_REMEMBER_TOKEN']);
        $this->actingAs($this->user(UserRole::Admin))->get('/admin/users/'.$resident->id)->assertOk()
            ->assertSee($resident->email)->assertSee('09171234567')->assertSee('Verified')->assertSee('Registered')
            ->assertSee('Deactivate Resident')->assertDontSee($resident->password, false)->assertDontSee('SECRET_REMEMBER_TOKEN', false)
            ->assertDontSee('<script>bad()</script>', false)->assertDontSee('name="role"', false)->assertDontSee('name="password"', false)
            ->assertDontSee('name="email"', false)->assertDontSee('name="name"', false);
        $this->get('/admin/users/9999')->assertNotFound();
    }

    public function test_admin_targets_including_self_and_missing_users_are_blocked(): void
    {
        $admin = $this->user(UserRole::Admin);
        $other = $this->user(UserRole::Admin);
        $this->actingAs($admin);
        foreach ([$admin->id, $other->id, 9999] as $id) {
            $this->get('/admin/users/'.$id)->assertNotFound();
            $this->patch('/admin/users/'.$id.'/status', ['is_active' => 0, 'expected_is_active' => 1])->assertNotFound();
        }
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_activation_only_changes_active_flag_and_preserves_transaction_data(): void
    {
        $resident = $this->user(UserRole::Resident, ['email_verified_at' => null]);
        $before = $resident->fresh()->getRawOriginal();
        $service = Service::create(['name' => 'Clearance']);
        $schedule = Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5]);
        $reservation = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id]);
        $history = $reservation->statusHistories()->create(['to_status' => 'pending', 'changed_at' => now()]);
        $reservation = $reservation->fresh();
        $history = $history->fresh();
        $this->actingAs($this->user(UserRole::Admin))->patch('/admin/users/'.$resident->id.'/status', ['is_active' => 0, 'expected_is_active' => 1])
            ->assertRedirect(route('admin.users.show', $resident))->assertSessionHas('status', 'Resident account deactivated.');
        $this->assertFalse($resident->fresh()->is_active);
        $this->patch('/admin/users/'.$resident->id.'/status', ['is_active' => 1, 'expected_is_active' => 0])->assertSessionHas('status', 'Resident account activated.');
        $this->assertTrue($resident->fresh()->is_active);
        foreach (['name', 'email', 'contact_number', 'address', 'password', 'remember_token', 'role', 'email_verified_at', 'created_at'] as $field) {
            $this->assertSame($before[$field], $resident->fresh()->getRawOriginal($field));
        }
        $this->assertSame($reservation->getRawOriginal(), $reservation->fresh()->getRawOriginal());
        $this->assertSame($history->getRawOriginal(), $history->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('qr_tickets', 0);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public function test_deactivation_blocks_existing_session_and_login_reactivation_keeps_verification(): void
    {
        $resident = $this->user();
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->patch('/admin/users/'.$resident->id.'/status', ['is_active' => 0, 'expected_is_active' => 1])->assertSessionHas('status');
        $this->actingAs($resident->fresh())->get('/resident/reservations')->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $resident->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $resident->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->patch('/admin/users/'.$resident->id.'/status', ['is_active' => 1, 'expected_is_active' => 0])->assertSessionHas('status');
        $this->actingAs($resident->fresh())->get('/resident/home')->assertRedirect(route('verification.notice'));
        $this->assertNull($resident->fresh()->email_verified_at);
    }

    public function test_privileged_and_profile_fields_are_rejected_and_never_mass_assigned(): void
    {
        $resident = $this->user();
        $this->actingAs($this->user(UserRole::Admin));
        foreach (['role' => 'admin', 'email_verified_at' => '2026-10-06', 'password' => 'forged-password', 'remember_token' => 'forged-token', 'name' => 'Forged Name', 'email' => 'forged@example.test', 'contact_number' => '01234', 'address' => 'Forged Address'] as $field => $value) {
            $this->patch('/admin/users/'.$resident->id.'/status', ['is_active' => 0, 'expected_is_active' => 1, $field => $value])->assertSessionHasErrors($field);
            $this->assertTrue($resident->fresh()->is_active);
            $this->assertNotSame($value, $resident->fresh()->getRawOriginal($field));
        }
        $this->patch('/admin/users/'.$resident->id.'/status', ['is_active' => 0, 'expected_is_active' => 1, 'id' => 9999, 'is_admin' => true])->assertSessionHas('status');
        $this->assertFalse($resident->fresh()->is_active);
        $this->assertSame(UserRole::Resident, $resident->fresh()->role);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_invalid_filters_status_values_and_stale_updates_are_safe(): void
    {
        $resident = $this->user();
        $this->actingAs($this->user(UserRole::Admin));
        foreach (['search[]=bad' => 'search', 'search='.str_repeat('x', 256) => 'search', 'status=admin' => 'status', 'verification=yes' => 'verification'] as $query => $field) {
            $this->get('/admin/users?'.$query)->assertRedirect(route('admin.users.index'))->assertSessionHasErrors($field);
        }
        foreach ([[], ['is_active' => 'false', 'expected_is_active' => 1], ['is_active' => 2, 'expected_is_active' => 1], ['is_active' => [], 'expected_is_active' => 1], ['is_active' => 0]] as $data) {
            $this->patch('/admin/users/'.$resident->id.'/status', $data)->assertSessionHasErrors();
            $this->assertTrue($resident->fresh()->is_active);
        }
        $this->patch('/admin/users/'.$resident->id.'/status', ['is_active' => 0, 'expected_is_active' => 1])->assertSessionHas('status');
        $this->patch('/admin/users/'.$resident->id.'/status', ['is_active' => 1, 'expected_is_active' => 1])->assertSessionHasErrors('is_active');
        $this->assertFalse($resident->fresh()->is_active);
    }

    public function test_no_creation_edit_delete_or_role_management_routes_and_csrf_is_enforced(): void
    {
        $resident = $this->user();
        $this->actingAs($this->user(UserRole::Admin));
        $this->post('/admin/users', ['role' => 'admin'])->assertStatus(405);
        $this->get('/admin/users/create')->assertNotFound();
        $this->get('/admin/users/'.$resident->id.'/edit')->assertNotFound();
        $this->put('/admin/users/'.$resident->id, ['role' => 'admin'])->assertStatus(405);
        $this->delete('/admin/users/'.$resident->id)->assertStatus(405);
        $this->get('/admin/register')->assertNotFound();
        $this->app['env'] = 'local';
        $this->patch('/admin/users/'.$resident->id.'/status', ['is_active' => 0, 'expected_is_active' => 1])->assertStatus(419);
        $this->assertTrue($resident->fresh()->is_active);
        $this->assertDatabaseCount('users', 2);
    }

    private function user(UserRole $role = UserRole::Resident, array $attributes = []): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true, ...$attributes])->save();

        return $user;
    }
}
