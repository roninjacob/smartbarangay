<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CivicRedesignTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_resident_quick_navigation_uses_protected_existing_routes_without_writing_business_data(): void
    {
        $resident = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $this->actingAs($resident)->get(route('resident.home'))->assertOk()
            ->assertSee('Resident quick navigation')->assertSee(route('resident.profile.edit'))
            ->assertSee(route('resident.reservations.create'))->assertSee(route('resident.qr-tickets.index'))
            ->assertDontSee(route('admin.reservations.index'));
        $this->get(route('resident.settings.edit'))->assertOk()->assertSee('Resident quick navigation');
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('resident_profiles', 0);
        $this->assertDatabaseCount('reservation_payments', 0);
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->get(route('admin.home'))->assertForbidden();
    }

    public function test_admin_navigation_keeps_all_report_destinations_and_no_resident_mobile_bar(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $response = $this->actingAs($admin)->get(route('admin.home'))->assertOk()
            ->assertDontSee('Resident quick navigation');
        foreach (['reservations', 'collections', 'checkins', 'documents'] as $report) {
            $response->assertSee(route('admin.reports.'.$report));
        }
        $this->get(route('resident.home'))->assertForbidden();
    }

    public function test_public_branding_does_not_expose_authenticated_navigation_or_collect_extra_registration_fields(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('images/smartbarangay-mark.svg')
            ->assertDontSee('Resident quick navigation')->assertSee(route('register'));
        $this->get(route('register'))->assertOk()->assertSee('images/smartbarangay-mark.svg')
            ->assertDontSee('name="role"', false)->assertDontSee('name="civil_status"', false)
            ->assertDontSee('name="emergency_contact_number"', false);
        $this->assertDatabaseCount('users', 0);
    }
}
