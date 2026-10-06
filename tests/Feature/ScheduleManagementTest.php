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

class ScheduleManagementTest extends TestCase
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
            ['GET', '/admin/schedules'], ['GET', '/admin/schedules/create'], ['POST', '/admin/schedules'],
            ['GET', '/admin/schedules/1/edit'], ['PUT', '/admin/schedules/1'],
            ['PATCH', '/admin/schedules/1/status'], ['DELETE', '/admin/schedules/1'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_all_schedule_endpoints_preserve_guest_role_verification_and_inactive_protection(string $method, string $path): void
    {
        $schedule = $this->schedule();
        $before = $schedule->fresh()->getRawOriginal();
        $data = [...$this->data(), 'date' => '2026-10-25', 'is_active' => false];
        $this->call($method, $path, $data)->assertRedirect(route('login'));
        $resident = $this->user(UserRole::Resident);
        $this->actingAs($resident)->call($method, $path, $data)->assertForbidden();
        $resident->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($resident)->call($method, $path, $data)->assertForbidden();
        $admin = $this->user();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->call($method, $path, $data)->assertRedirect(route('verification.notice'));
        $admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($admin)->call($method, $path, $data)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame($before, $schedule->fresh()->getRawOriginal());
        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_verified_admin_can_view_empty_list_create_schedule_and_see_navigation(): void
    {
        $this->actingAs($this->user())->get('/admin/schedules')->assertOk()->assertSee('No schedules yet');
        $this->get('/admin/schedules/create')->assertOk()->assertSee('Appointment Date')->assertSee('name="_token"', false);
        $this->post('/admin/schedules', [...$this->data(), 'id' => 999, 'is_active' => false])->assertRedirect('/admin/schedules')->assertSessionHas('status');
        $this->assertDatabaseHas('schedules', ['start_time' => '08:00:00', 'end_time' => '09:00:00', 'capacity' => 10, 'is_active' => true]);
        $this->assertSame('2026-10-20', Schedule::firstOrFail()->date->toDateString());
        $this->assertDatabaseMissing('schedules', ['id' => 999]);
        $this->get('/admin/schedules')->assertOk()->assertSee('Oct 20, 2026')->assertSee('8:00 AM')->assertSee('9:00 AM')->assertSee('Active');
        $this->get('/admin/home')->assertOk()->assertSee('href="'.route('admin.schedules.index').'"', false);
        $this->actingAs($this->user(UserRole::Resident))->get('/resident/home')->assertOk()->assertDontSee(route('admin.schedules.index'), false);
    }

    public function test_admin_can_edit_activate_deactivate_and_remove_unused_schedule(): void
    {
        $schedule = $this->schedule();
        $this->actingAs($this->user())->get('/admin/schedules/1/edit')->assertOk()->assertSee('value="08:00"', false);
        foreach ([false, false, true, false] as $active) {
            $this->patch('/admin/schedules/1/status', ['is_active' => $active, 'capacity' => 999])->assertRedirect('/admin/schedules');
            $this->assertSame($active, $schedule->fresh()->is_active);
            $this->assertSame(10, $schedule->fresh()->capacity);
        }
        $this->get('/admin/schedules')->assertOk()->assertSee('Inactive')->assertSee('Activate schedule 1');
        $this->put('/admin/schedules/1', ['date' => '2026-10-21', 'start_time' => '13:00', 'end_time' => '14:30', 'capacity' => 20, 'is_active' => true])
            ->assertRedirect('/admin/schedules');
        $this->assertDatabaseHas('schedules', ['id' => 1, 'start_time' => '13:00:00', 'end_time' => '14:30:00', 'capacity' => 20, 'is_active' => false]);
        $this->assertSame('2026-10-21', $schedule->fresh()->date->toDateString());
        $this->delete('/admin/schedules/1')->assertRedirect('/admin/schedules')->assertSessionHas('status');
        $this->assertDatabaseMissing('schedules', ['id' => 1]);
    }

    public function test_invalid_values_are_rejected_and_validation_feedback_renders(): void
    {
        $this->actingAs($this->user());
        $invalid = [
            ['date', null], ['date', 'not-a-date'], ['date', '2026-02-30'], ['date', ['bad']],
            ['start_time', null], ['start_time', '24:00'], ['start_time', '08:60'], ['start_time', '8 AM'], ['start_time', ['bad']],
            ['end_time', null], ['end_time', '08:00'], ['end_time', '07:59'], ['end_time', 'invalid'], ['end_time', ['bad']],
            ['capacity', null], ['capacity', 0], ['capacity', -1], ['capacity', 'abc'], ['capacity', '1.5'], ['capacity', 4294967296], ['capacity', ['bad']],
        ];
        foreach ($invalid as [$field, $value]) {
            $this->from('/admin/schedules/create')->post('/admin/schedules', array_replace($this->data(), [$field => $value]))
                ->assertRedirect('/admin/schedules/create')->assertSessionHasErrors($field);
            $this->get('/admin/schedules/create')->assertOk()->assertSee('Please correct the highlighted fields');
        }
        $this->assertDatabaseCount('schedules', 0);
        $schedule = $this->schedule();
        $before = $schedule->fresh()->getRawOriginal();
        $this->put('/admin/schedules/1', [...$this->data(), 'end_time' => '08:00'])->assertSessionHasErrors('end_time');
        $this->assertSame($before, $schedule->fresh()->getRawOriginal());
    }

    public function test_duplicate_slot_validation_respects_composite_constraint_and_own_record(): void
    {
        $this->schedule();
        $this->actingAs($this->user())->post('/admin/schedules', $this->data())->assertSessionHasErrors('date');
        $this->put('/admin/schedules/1', $this->data())->assertRedirect('/admin/schedules')->assertSessionHasNoErrors();
        $this->post('/admin/schedules', [...$this->data(), 'start_time' => '09:00', 'end_time' => '10:00'])->assertRedirect('/admin/schedules');
        $this->put('/admin/schedules/2', $this->data())->assertSessionHasErrors('date');
        $this->assertDatabaseHas('schedules', ['id' => 2, 'start_time' => '09:00:00']);
        $this->assertDatabaseCount('schedules', 2);
    }

    public function test_referenced_schedule_cannot_be_removed_and_other_foundations_remain_unchanged(): void
    {
        $service = Service::create(['name' => 'Barangay Clearance', 'description' => 'Original']);
        $requirement = $service->serviceRequirements()->create(['name' => 'Valid ID']);
        $serviceBefore = $service->fresh()->getRawOriginal();
        $requirementBefore = $requirement->fresh()->getRawOriginal();
        $schedule = $this->schedule();
        $reservation = Reservation::create(['user_id' => $this->user(UserRole::Resident)->id, 'service_id' => $service->id, 'schedule_id' => $schedule->id]);
        $before = $reservation->fresh()->getRawOriginal();
        $this->actingAs($this->user())->get('/admin/schedules')->assertOk()->assertSee('In use')
            ->assertDontSee('data-bs-target="#delete-schedule-1"', false);
        $this->delete('/admin/schedules/1')->assertRedirect('/admin/schedules')->assertSessionHasErrors('schedule');
        $this->patch('/admin/schedules/1/status', ['is_active' => false])->assertRedirect('/admin/schedules');
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
        $this->assertSame($schedule->id, $reservation->fresh()->schedule->id);
        $this->assertSame($serviceBefore, $service->fresh()->getRawOriginal());
        $this->assertSame($requirementBefore, $requirement->fresh()->getRawOriginal());
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('schedules', 1);
    }

    public function test_status_rejects_invalid_boolean_and_missing_schedules_return_404(): void
    {
        $schedule = $this->schedule();
        $this->actingAs($this->user());
        foreach ([[], ['is_active' => 'wrong'], ['is_active' => 2]] as $data) {
            $this->patch('/admin/schedules/1/status', $data)->assertSessionHasErrors('is_active');
        }
        $this->assertTrue($schedule->fresh()->is_active);
        $this->get('/admin/schedules/999/edit')->assertNotFound();
        $this->put('/admin/schedules/999', $this->data())->assertNotFound();
        $this->patch('/admin/schedules/999/status', ['is_active' => false])->assertNotFound();
        $this->delete('/admin/schedules/999')->assertNotFound();
    }

    public function test_schedule_list_is_paginated_chronological_and_uses_no_reservation_count_query(): void
    {
        for ($i = 0; $i < 16; $i++) {
            $this->schedule(['date' => sprintf('2026-11-%02d', 16 - $i)]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($this->user())->get('/admin/schedules')->assertOk()->assertSee('Showing 1–15 of 16 schedules')->assertSee('Nov 1, 2026')->assertDontSee('Nov 16, 2026');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(4, count(array_filter($queries, fn ($query) => str_starts_with(strtolower($query['query']), 'select'))));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/count\([^)]*\).*from ["`]?reservations/i', $query['query']);
        }
        $this->get('/admin/schedules?page=2')->assertOk()->assertSee('Nov 16, 2026');
    }

    public function test_schedule_mutations_require_csrf(): void
    {
        $this->schedule();
        $this->actingAs($this->user());
        $this->app['env'] = 'local';
        $this->post('/admin/schedules', $this->data())->assertStatus(419);
        $this->put('/admin/schedules/1', $this->data())->assertStatus(419);
        $this->patch('/admin/schedules/1/status', ['is_active' => false])->assertStatus(419);
        $this->delete('/admin/schedules/1')->assertStatus(419);
        $this->assertDatabaseHas('schedules', ['id' => 1, 'capacity' => 10, 'is_active' => true]);
    }

    private function data(): array
    {
        return ['date' => '2026-10-20', 'start_time' => '08:00', 'end_time' => '09:00', 'capacity' => 10];
    }

    private function schedule(array $overrides = []): Schedule
    {
        return Schedule::create(array_replace(['date' => '2026-10-20', 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'capacity' => 10], $overrides));
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }
}
