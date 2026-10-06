<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.foreign_key_constraints' => true,
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

    public static function endpoints(): array
    {
        return [
            'list' => ['GET', '/admin/services'],
            'create form' => ['GET', '/admin/services/create'],
            'store' => ['POST', '/admin/services'],
            'edit form' => ['GET', '/admin/services/1/edit'],
            'update' => ['PUT', '/admin/services/1'],
            'status' => ['PATCH', '/admin/services/1/status'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_every_service_endpoint_enforces_guest_role_and_verification(string $method, string $path): void
    {
        $service = Service::create(['name' => 'Original']);
        $data = ['name' => 'Unauthorized change', 'is_active' => false];
        $this->call($method, $path, $data)->assertRedirect(route('login'));
        $resident = $this->user(UserRole::Resident);
        $this->actingAs($resident)->call($method, $path, $data)->assertForbidden();
        $resident->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($resident)->call($method, $path, $data)->assertForbidden();
        $admin = $this->user();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->call($method, $path, $data)->assertRedirect(route('verification.notice'));
        $this->assertDatabaseCount('services', 1);
        $this->assertSame('Original', $service->fresh()->name);
        $this->assertTrue($service->fresh()->is_active);
    }

    #[DataProvider('endpoints')]
    public function test_inactive_admin_cannot_access_or_modify_services(string $method, string $path): void
    {
        Service::create(['name' => 'Original']);
        $admin = $this->user();
        $admin->forceFill(['is_active' => false])->save();
        $this->actingAs($admin)->call($method, $path, ['name' => 'Changed', 'is_active' => false])
            ->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('services', ['name' => 'Original', 'is_active' => true]);
        $this->assertDatabaseCount('services', 1);
    }

    public function test_admin_sees_empty_catalog_and_can_create_active_service_with_safe_fields(): void
    {
        $this->actingAs($this->user())->get('/admin/services')->assertOk()->assertSee('No services yet')
            ->assertSee('Barangay Calayo')->assertSee('aria-current="page"', false);
        $this->get('/admin/services/create')->assertOk()->assertSee('name="_token"', false)->assertSee('Service / Document Name');
        $this->post('/admin/services', ['name' => '  Barangay Clearance  ', 'description' => '  Proof of clearance.  ', 'is_active' => false, 'id' => 900])
            ->assertRedirect(route('admin.services.index'))->assertSessionHas('status');
        $this->assertDatabaseHas('services', ['name' => 'Barangay Clearance', 'description' => 'Proof of clearance.', 'is_active' => true]);
        $this->assertDatabaseMissing('services', ['id' => 900]);
    }

    public function test_invalid_and_duplicate_names_and_descriptions_are_rejected(): void
    {
        Service::create(['name' => 'Existing']);
        $this->actingAs($this->user());
        foreach (['', '   ', str_repeat('x', 256), ['invalid'], 'Existing', ' Existing '] as $name) {
            $this->from('/admin/services/create')->post('/admin/services', ['name' => $name])
                ->assertRedirect('/admin/services/create')->assertSessionHasErrors('name');
            $this->get('/admin/services/create')->assertOk()->assertSee('Please correct the highlighted fields');
        }
        $this->post('/admin/services', ['name' => 'New', 'description' => str_repeat('x', 5001)])->assertSessionHasErrors('description');
        $this->post('/admin/services', ['name' => 'New', 'description' => ['bad']])->assertSessionHasErrors('description');
        $this->assertDatabaseCount('services', 1);
        $this->get('/admin/services/create')->assertOk()->assertSee('Please correct the highlighted fields');
    }

    public function test_admin_can_edit_without_changing_availability_or_overwriting_another_service(): void
    {
        $service = Service::create(['name' => 'Clearance', 'is_active' => false]);
        Service::create(['name' => 'Certificate']);
        $this->actingAs($this->user())->get('/admin/services/'.$service->id.'/edit')->assertOk()
            ->assertSee('Clearance')->assertSee('Inactive')->assertSee('value="PUT"', false);
        $this->put('/admin/services/'.$service->id, ['name' => 'Clearance', 'description' => null, 'is_active' => true])
            ->assertRedirect(route('admin.services.index'))->assertSessionHasNoErrors();
        $this->assertFalse($service->fresh()->is_active);
        $this->put('/admin/services/'.$service->id, ['name' => 'Certificate'])->assertSessionHasErrors('name');
        $this->put('/admin/services/'.$service->id, ['name' => 'Updated Clearance', 'description' => 'Updated details'])
            ->assertRedirect(route('admin.services.index'));
        $this->assertDatabaseHas('services', ['id' => $service->id, 'name' => 'Updated Clearance', 'description' => 'Updated details', 'is_active' => false]);
    }

    public function test_deactivation_and_reactivation_preserve_related_reservation_and_are_idempotent(): void
    {
        $service = Service::create(['name' => 'Clearance']);
        $schedule = Schedule::create(['date' => '2026-10-12', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5]);
        $reservation = Reservation::create(['user_id' => $this->user(UserRole::Resident)->id, 'service_id' => $service->id, 'schedule_id' => $schedule->id]);
        $this->actingAs($this->user());
        foreach ([false, false, true] as $active) {
            $this->patch('/admin/services/'.$service->id.'/status', ['is_active' => $active, 'name' => 'Ignored'])
                ->assertRedirect(route('admin.services.index'));
            $this->assertSame($active, $service->fresh()->is_active);
            $this->assertSame('Clearance', $reservation->fresh()->service->name);
            $this->assertDatabaseCount('reservations', 1);
        }
        $this->delete('/admin/services/'.$service->id)->assertStatus(405);
        $this->assertDatabaseCount('services', 1);
    }

    public function test_status_requires_explicit_boolean_and_missing_service_returns_404(): void
    {
        $service = Service::create(['name' => 'Clearance']);
        $this->actingAs($this->user());
        foreach ([[], ['is_active' => 'wrong'], ['is_active' => 2]] as $data) {
            $this->patch('/admin/services/'.$service->id.'/status', $data)->assertSessionHasErrors('is_active');
        }
        $this->assertTrue($service->fresh()->is_active);
        $this->get('/admin/services/999/edit')->assertNotFound();
        $this->put('/admin/services/999', ['name' => 'Name'])->assertNotFound();
        $this->patch('/admin/services/999/status', ['is_active' => false])->assertNotFound();
    }

    public function test_catalog_paginates_active_and_inactive_services_and_escapes_text(): void
    {
        for ($i = 0; $i < 16; $i++) {
            Service::create(['name' => sprintf('Service %02d', $i), 'is_active' => $i !== 0]);
        }
        $this->actingAs($this->user())->get('/admin/services')->assertOk()->assertSee('Service 00')->assertSee('Inactive')
            ->assertSee('Active')->assertDontSee('Service 15')->assertSee('Showing 1–15 of 16 services');
        $this->get('/admin/services?page=2')->assertOk()->assertSee('Service 15')->assertSee('Showing 16–16 of 16 services');
        $service = Service::create(['name' => '<script>name()</script>', 'description' => '<img src=x onerror=alert(1)>']);
        $this->get('/admin/services')->assertOk()->assertSee($service->name)
            ->assertDontSee($service->name, false)->assertDontSee($service->description, false);
        $this->get('/admin/services/'.$service->id.'/edit')->assertOk()->assertSee($service->description)
            ->assertDontSee($service->description, false);
    }

    public function test_mutations_require_csrf_tokens(): void
    {
        $service = Service::create(['name' => 'Original']);
        $this->actingAs($this->user());
        $this->app['env'] = 'local';
        $this->post('/admin/services', ['name' => 'New'])->assertStatus(419);
        $this->put('/admin/services/'.$service->id, ['name' => 'Changed'])->assertStatus(419);
        $this->patch('/admin/services/'.$service->id.'/status', ['is_active' => false])->assertStatus(419);
        $this->assertDatabaseHas('services', ['name' => 'Original', 'is_active' => true]);
        $this->assertDatabaseCount('services', 1);
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }
}
