<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResidentReservationWizardTest extends TestCase
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
            ['GET', '/resident/reservations/create'], ['POST', '/resident/reservations/service'],
            ['GET', '/resident/reservations/requirements'], ['POST', '/resident/reservations/requirements'],
            ['GET', '/resident/reservations/schedule'], ['POST', '/resident/reservations/schedule'],
            ['GET', '/resident/reservations/confirm'], ['POST', '/resident/reservations'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_access_requires_verified_active_resident(string $method, string $path): void
    {
        $this->call($method, $path)->assertRedirect(route('login'));
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->call($method, $path)->assertForbidden();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->call($method, $path)->assertForbidden();
        $resident = $this->user();
        $resident->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($resident)->call($method, $path)->assertRedirect(route('verification.notice'));
        $resident->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($resident)->call($method, $path)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_active_choices_and_readonly_requirements_and_empty_states(): void
    {
        $this->actingAs($this->user())->get('/resident/reservations/create')->assertOk()->assertSee('No services available');
        $service = Service::create(['name' => 'Clearance', 'description' => '<script>alert(1)</script>']);
        Service::create(['name' => 'Hidden service', 'is_active' => false]);
        $service->serviceRequirements()->create(['name' => 'Valid ID', 'description' => 'Bring original.']);
        $service->serviceRequirements()->create(['name' => 'Supporting document', 'is_required' => false]);
        $this->get('/resident/reservations/create')->assertSee('Clearance')->assertDontSee('Hidden service')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->post('/resident/reservations/service', ['service_id' => $service->id])->assertRedirect(route('resident.reservations.requirements'));
        $this->get('/resident/reservations/requirements')->assertOk()->assertSee('Valid ID')->assertSee('Required')->assertSee('Optional')
            ->assertDontSee('type="file"', false);
        $this->post('/resident/reservations/requirements')->assertRedirect(route('resident.reservations.schedule'));
        $this->get('/resident/reservations/schedule')->assertOk()->assertSee('No schedules available');
        $this->schedule();
        $this->schedule(['date' => '2026-12-31', 'is_active' => false]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/resident/reservations/schedule')->assertOk()->assertSee('November 1, 2026')->assertDontSee('December 31, 2026');
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/count\([^)]*\).*from ["`]?reservations/i', $query['query']);
        }
        DB::disableQueryLog();
    }

    public function test_full_flow_creates_pending_with_server_owned_values_and_prevents_replay(): void
    {
        $resident = $this->user();
        $other = $this->user();
        $service = Service::create(['name' => 'Clearance']);
        $schedule = $this->schedule();
        $serviceBefore = $service->fresh()->getRawOriginal();
        $scheduleBefore = $schedule->fresh()->getRawOriginal();
        $this->actingAs($resident);
        $token = $this->completeDraft($service, $schedule);
        $this->get('/resident/reservations/confirm')->assertOk()->assertSee('Clearance')->assertSee('November 1, 2026')->assertSee('Pending')
            ->assertSee('No requirements have been listed')->assertSee('name="_token"', false);
        $payload = ['confirmation_token' => $token, 'user_id' => $other->id, 'status' => 'approved', 'service_id' => 999, 'schedule_id' => 999, 'admin_notes' => 'Injected'];
        $this->post('/resident/reservations', $payload)->assertRedirect(route('resident.home'))->assertSessionHas('status')->assertSessionMissing('reservation_wizard');
        $reservation = Reservation::sole();
        $this->assertSame($resident->id, $reservation->user_id);
        $this->assertSame($service->id, $reservation->service_id);
        $this->assertSame($schedule->id, $reservation->schedule_id);
        $this->assertSame(ReservationStatus::Pending, $reservation->status);
        $this->assertNull($reservation->admin_notes);
        $this->assertSame($serviceBefore, $service->fresh()->getRawOriginal());
        $this->assertSame($scheduleBefore, $schedule->fresh()->getRawOriginal());
        $this->post('/resident/reservations', $payload)->assertRedirect(route('resident.reservations.create'));
        $this->assertDatabaseCount('reservations', 1);
        foreach (['reservation_attachments', 'qr_tickets', 'checkin_logs', 'reservation_status_histories'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->get('/resident/home')->assertOk()->assertSee('Clearance');
        $this->assertSame(1, $resident->reservations()->count());
        $this->assertSame(0, $other->reservations()->count());
    }

    public function test_missing_inactive_and_manipulated_selections_are_rejected(): void
    {
        $service = Service::create(['name' => 'Clearance']);
        $inactive = Service::create(['name' => 'Inactive', 'is_active' => false]);
        $this->actingAs($this->user());
        foreach ([null, '', 999, $inactive->id, ['id' => $service->id], '1 OR 1=1'] as $value) {
            $this->post('/resident/reservations/service', ['service_id' => $value])->assertSessionHasErrors('service_id')->assertSessionMissing('reservation_wizard');
        }
        $this->post('/resident/reservations/service', ['service_id' => $service->id]);
        $this->post('/resident/reservations/requirements');
        $inactiveSchedule = $this->schedule(['is_active' => false]);
        foreach ([null, '', 999, $inactiveSchedule->id, ['id' => 1], '1 OR 1=1'] as $value) {
            $this->post('/resident/reservations/schedule', ['schedule_id' => $value])->assertSessionHasErrors('schedule_id');
        }
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_steps_cannot_be_skipped_and_service_change_clears_later_state(): void
    {
        $this->actingAs($this->user());
        foreach (['requirements', 'schedule', 'confirm'] as $step) {
            $this->get('/resident/reservations/'.$step)->assertRedirect(route('resident.reservations.create'));
        }
        $service = Service::create(['name' => 'Clearance']);
        $schedule = $this->schedule();
        $this->post('/resident/reservations/service', ['service_id' => $service->id]);
        $this->get('/resident/reservations/schedule')->assertRedirect(route('resident.reservations.requirements'));
        $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id])->assertRedirect(route('resident.reservations.requirements'));
        $this->post('/resident/reservations/requirements');
        $this->get('/resident/reservations/confirm')->assertRedirect(route('resident.reservations.schedule'));
        $token = $this->completeDraft($service, $schedule);
        $this->post('/resident/reservations/service', ['service_id' => $service->id])
            ->assertSessionMissing('reservation_wizard.schedule_id')->assertSessionMissing('reservation_wizard.confirmation_token')
            ->assertSessionMissing('reservation_wizard.requirements_reviewed');
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.requirements'));
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_another_account_cannot_consume_session_draft(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->actingAs($first);
        $token = $this->completeDraft(Service::create(['name' => 'Clearance']), $this->schedule());
        $this->actingAs($second)->post('/resident/reservations', ['confirmation_token' => $token])
            ->assertRedirect(route('resident.reservations.create'))->assertSessionMissing('reservation_wizard');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_confirmation_tokens_reject_missing_wrong_and_stale_tabs(): void
    {
        $this->actingAs($this->user());
        $service = Service::create(['name' => 'Clearance']);
        $schedule = $this->schedule();
        $old = $this->completeDraft($service, $schedule);
        $this->post('/resident/reservations', [])->assertSessionHasErrors('confirmation_token');
        $this->post('/resident/reservations', ['confirmation_token' => (string) Str::uuid()])->assertSessionHasErrors('confirmation_token');
        $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id]);
        $this->post('/resident/reservations', ['confirmation_token' => $old])->assertSessionHasErrors('confirmation_token');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_final_submission_rechecks_active_and_existing_records(): void
    {
        $this->actingAs($this->user());
        $service = Service::create(['name' => 'Clearance']);
        $schedule = $this->schedule();
        $token = $this->completeDraft($service, $schedule);
        $service->update(['is_active' => false]);
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.create'))->assertSessionHasErrors('reservation');
        $service->update(['is_active' => true]);
        $token = $this->completeDraft($service, $schedule);
        $schedule->update(['is_active' => false]);
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.schedule'));
        $schedule->update(['is_active' => true]);
        $token = $this->completeDraft($service, $schedule);
        $schedule->delete();
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.schedule'));
        $token = $this->completeDraft($service, $this->schedule());
        $service->delete();
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertRedirect(route('resident.reservations.create'));
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_wizard_mutations_require_csrf_and_all_routes_lock_the_session(): void
    {
        $this->actingAs($this->user());
        $this->app['env'] = 'local';
        foreach (self::endpoints() as [$method, $path]) {
            if ($method === 'POST') {
                $this->post($path)->assertStatus(419);
            }
        }
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->getName() ?? '', 'resident.reservations.')) {
                $this->assertTrue($route->locksFor() > 0);
            }
        }
        $this->assertDatabaseCount('reservations', 0);
    }

    private function completeDraft(Service $service, Schedule $schedule): string
    {
        $this->post('/resident/reservations/service', ['service_id' => $service->id])->assertRedirect(route('resident.reservations.requirements'));
        $this->post('/resident/reservations/requirements')->assertRedirect(route('resident.reservations.schedule'));
        $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id])->assertRedirect(route('resident.reservations.confirm'));

        return session('reservation_wizard.confirmation_token');
    }

    private function schedule(array $overrides = []): Schedule
    {
        return Schedule::create(array_replace(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5], $overrides));
    }

    private function user(UserRole $role = UserRole::Resident): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }
}
