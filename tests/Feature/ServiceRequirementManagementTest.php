<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\ReservationAttachment;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\ServiceRequirement;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceRequirementManagementTest extends TestCase
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
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public static function endpoints(): array
    {
        return [
            'list' => ['GET', '/admin/services/1/requirements'],
            'create form' => ['GET', '/admin/services/1/requirements/create'],
            'store' => ['POST', '/admin/services/1/requirements'],
            'edit form' => ['GET', '/admin/services/1/requirements/1/edit'],
            'update' => ['PUT', '/admin/services/1/requirements/1'],
            'destroy' => ['DELETE', '/admin/services/1/requirements/1'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_all_requirement_endpoints_require_authenticated_verified_admin(string $method, string $path): void
    {
        $service = Service::create(['name' => 'Barangay Clearance']);
        $requirement = $service->serviceRequirements()->create(['name' => 'Valid ID']);
        $data = ['name' => 'Changed', 'is_required' => false];
        $this->call($method, $path, $data)->assertRedirect(route('login'));
        $resident = $this->user(UserRole::Resident);
        $this->actingAs($resident)->call($method, $path, $data)->assertForbidden();
        $resident->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($resident)->call($method, $path, $data)->assertForbidden();
        $admin = $this->user();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->call($method, $path, $data)->assertRedirect(route('verification.notice'));
        $this->assertDatabaseCount('service_requirements', 1);
        $this->assertSame('Valid ID', $requirement->fresh()->name);
        $this->assertTrue($requirement->fresh()->is_required);
    }

    #[DataProvider('endpoints')]
    public function test_inactive_admin_cannot_access_requirements(string $method, string $path): void
    {
        $service = Service::create(['name' => 'Barangay Clearance']);
        $service->serviceRequirements()->create(['name' => 'Valid ID']);
        $admin = $this->user();
        $admin->forceFill(['is_active' => false])->save();
        $this->actingAs($admin)->call($method, $path, ['name' => 'Changed', 'is_required' => false])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('service_requirements', ['name' => 'Valid ID', 'is_required' => true]);
        $this->assertDatabaseCount('service_requirements', 1);
    }

    public function test_admin_sees_scoped_requirements_and_empty_state_with_catalog_navigation(): void
    {
        $service = Service::create(['name' => 'Barangay Clearance']);
        $other = Service::create(['name' => 'Other service']);
        $other->serviceRequirements()->create(['name' => 'Other requirement']);
        $this->actingAs($this->user())->get('/admin/services/1/requirements')->assertOk()
            ->assertSee('No requirements yet')->assertSee('Barangay Clearance')->assertDontSee('Other requirement');
        $this->get('/admin/services')->assertOk()->assertSee(route('admin.services.requirements.index', $service), false);
        $service->serviceRequirements()->create(['name' => 'Valid ID', 'description' => 'Bring an original ID.']);
        $service->serviceRequirements()->create(['name' => 'Supporting certificate', 'is_required' => false]);
        $this->get('/admin/services/1/requirements')->assertOk()->assertSee('Valid ID')->assertSee('Bring an original ID.')
            ->assertSee('Required')->assertSee('Optional')->assertDontSee('Other requirement')->assertSee('Delete requirement?');
        $this->get('/admin/services/1/requirements/create')->assertOk()->assertSee('Requirement Name')->assertSee('name="_token"', false);
    }

    public function test_admin_can_create_edit_and_delete_without_changing_parent_or_other_requirements(): void
    {
        $service = Service::create(['name' => 'Clearance', 'description' => 'Original description', 'is_active' => false]);
        $other = Service::create(['name' => 'Other service']);
        $otherRequirement = $other->serviceRequirements()->create(['name' => 'Unchanged']);
        $before = $service->fresh()->getRawOriginal();
        $this->actingAs($this->user())->post('/admin/services/1/requirements', [
            'name' => '  Valid ID  ', 'description' => '  Original copy  ', 'is_required' => '1', 'service_id' => $other->id, 'id' => 999, 'reservation_id' => 999,
        ])->assertRedirect('/admin/services/1/requirements')->assertSessionHas('status');
        $requirement = $service->serviceRequirements()->firstOrFail();
        $this->assertSame('Valid ID', $requirement->name);
        $this->assertSame('Original copy', $requirement->description);
        $this->assertTrue($requirement->is_required);
        $this->assertNotSame(999, $requirement->id);
        $path = '/admin/services/1/requirements/'.$requirement->id;
        $this->get($path.'/edit')->assertOk()->assertSee('Valid ID');
        $this->put($path, ['name' => 'Updated ID', 'description' => null, 'is_required' => '0', 'service_id' => $other->id])
            ->assertRedirect('/admin/services/1/requirements');
        $this->assertSame($service->id, $requirement->fresh()->service_id);
        $this->assertFalse($requirement->fresh()->is_required);
        $this->assertSame('Updated ID', $requirement->fresh()->name);
        $this->delete($path)->assertRedirect('/admin/services/1/requirements')->assertSessionHas('status');
        $this->assertDatabaseMissing('service_requirements', ['id' => $requirement->id]);
        $this->assertSame($before, $service->fresh()->getRawOriginal());
        $this->assertSame('Unchanged', $otherRequirement->fresh()->name);
        $this->assertDatabaseCount('services', 2);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_nested_routes_reject_requirement_from_another_service_and_missing_records(): void
    {
        Service::create(['name' => 'First']);
        $other = Service::create(['name' => 'Second']);
        $requirement = $other->serviceRequirements()->create(['name' => 'Other ID']);
        $this->actingAs($this->user());
        $this->get('/admin/services/1/requirements/1/edit')->assertNotFound();
        $this->put('/admin/services/1/requirements/1', ['name' => 'Changed', 'is_required' => true])->assertNotFound();
        $this->delete('/admin/services/1/requirements/1')->assertNotFound();
        $this->get('/admin/services/999/requirements')->assertNotFound();
        $this->post('/admin/services/999/requirements', ['name' => 'ID', 'is_required' => true])->assertNotFound();
        $this->get('/admin/services/1/requirements/999/edit')->assertNotFound();
        $this->assertSame('Other ID', $requirement->fresh()->name);
        $this->assertDatabaseCount('service_requirements', 1);
    }

    public function test_invalid_input_is_rejected_and_can_be_redisplayed_safely(): void
    {
        Service::create(['name' => 'Clearance']);
        $this->actingAs($this->user());
        $invalid = [
            [['name' => ''], 'name'], [['name' => '   '], 'name'], [['name' => ['bad']], 'name'],
            [['name' => str_repeat('x', 256)], 'name'], [['description' => ['bad']], 'description'],
            [['description' => str_repeat('x', 5001)], 'description'], [['is_required' => 'wrong'], 'is_required'],
            [['is_required' => null], 'is_required'], [['is_required' => ['bad']], 'is_required'],
        ];
        foreach ($invalid as [$fields, $error]) {
            $this->from('/admin/services/1/requirements/create')->post('/admin/services/1/requirements', array_replace(['name' => 'ID', 'is_required' => true], $fields))
                ->assertRedirect('/admin/services/1/requirements/create')->assertSessionHasErrors($error);
            $this->get('/admin/services/1/requirements/create')->assertOk()->assertSee('Please correct the highlighted fields');
        }
        $this->assertDatabaseCount('service_requirements', 0);
        $requirement = ServiceRequirement::create(['service_id' => 1, 'name' => 'Unchanged']);
        $this->put('/admin/services/1/requirements/1', ['name' => ' ', 'is_required' => true])->assertSessionHasErrors('name');
        $this->assertSame('Unchanged', $requirement->fresh()->name);
    }

    public function test_duplicate_names_are_scoped_to_service_and_update_ignores_own_record(): void
    {
        $first = Service::create(['name' => 'First']);
        Service::create(['name' => 'Second']);
        $requirement = $first->serviceRequirements()->create(['name' => 'Valid ID']);
        $this->actingAs($this->user())->post('/admin/services/1/requirements', ['name' => ' Valid ID ', 'is_required' => true])->assertSessionHasErrors('name');
        $this->post('/admin/services/2/requirements', ['name' => 'Valid ID', 'is_required' => true])->assertRedirect('/admin/services/2/requirements');
        $this->put('/admin/services/1/requirements/'.$requirement->id, ['name' => 'Valid ID', 'is_required' => true])->assertSessionHasNoErrors();
        $first->serviceRequirements()->create(['name' => 'Cedula']);
        $this->put('/admin/services/1/requirements/'.$requirement->id, ['name' => 'Cedula', 'is_required' => true])->assertSessionHasErrors('name');
    }

    public function test_linked_attachment_blocks_deletion_and_preserves_all_existing_data(): void
    {
        $service = Service::create(['name' => 'Clearance']);
        $requirement = $service->serviceRequirements()->create(['name' => 'Valid ID']);
        $schedule = Schedule::create(['date' => '2026-10-12', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5]);
        $reservation = Reservation::create(['user_id' => $this->user(UserRole::Resident)->id, 'service_id' => $service->id, 'schedule_id' => $schedule->id]);
        $attachment = ReservationAttachment::create([
            'reservation_id' => $reservation->id, 'service_requirement_id' => $requirement->id,
            'original_filename' => 'id.pdf', 'stored_path' => 'reservations/id.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1024,
        ]);
        $before = $attachment->fresh()->getRawOriginal();
        $this->actingAs($this->user())->get('/admin/services/1/requirements')->assertOk()->assertSee('In use')
            ->assertDontSee('data-bs-target="#delete-requirement-1"', false);
        $this->delete('/admin/services/1/requirements/1')->assertRedirect('/admin/services/1/requirements')->assertSessionHasErrors('requirement');
        $this->assertDatabaseHas('service_requirements', ['id' => $requirement->id, 'name' => 'Valid ID']);
        $this->assertSame($before, $attachment->fresh()->getRawOriginal());
        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame('Clearance', $reservation->fresh()->service->name);
    }

    public function test_listing_paginates_and_escapes_untrusted_text(): void
    {
        $service = Service::create(['name' => '<script>service()</script>']);
        for ($i = 0; $i < 16; $i++) {
            $service->serviceRequirements()->create(['name' => sprintf('Requirement %02d', $i)]);
        }
        $this->actingAs($this->user())->get('/admin/services/1/requirements')->assertOk()->assertSee('Showing 1–15 of 16 requirements')
            ->assertDontSee('Requirement 15')->assertDontSee($service->name, false);
        $this->get('/admin/services/1/requirements?page=2')->assertOk()->assertSee('Requirement 15');
        $requirement = $service->serviceRequirements()->create(['name' => '<script>name()</script>', 'description' => '<img src=x onerror=alert(1)>']);
        $this->get('/admin/services/1/requirements')->assertOk()->assertSee($requirement->name)
            ->assertDontSee($requirement->name, false)->assertDontSee($requirement->description, false);
        $this->get('/admin/services/1/requirements/'.$requirement->id.'/edit')->assertOk()->assertSee($requirement->description)
            ->assertDontSee($requirement->description, false);
    }

    public function test_all_requirement_mutations_require_csrf(): void
    {
        $service = Service::create(['name' => 'Clearance']);
        $service->serviceRequirements()->create(['name' => 'Valid ID']);
        $this->actingAs($this->user());
        $this->app['env'] = 'local';
        $this->post('/admin/services/1/requirements', ['name' => 'New', 'is_required' => true])->assertStatus(419);
        $this->put('/admin/services/1/requirements/1', ['name' => 'Changed', 'is_required' => false])->assertStatus(419);
        $this->delete('/admin/services/1/requirements/1')->assertStatus(419);
        $this->assertDatabaseHas('service_requirements', ['name' => 'Valid ID', 'is_required' => true]);
        $this->assertDatabaseCount('service_requirements', 1);
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }
}
