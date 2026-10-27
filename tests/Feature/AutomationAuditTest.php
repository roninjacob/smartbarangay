<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\ConfigurationAudit;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AutomationAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    public static function roles(): array
    {
        return [[UserRole::Admin], [UserRole::Resident]];
    }

    #[DataProvider('roles')]
    public function test_reset_revokes_two_database_sessions_and_keeps_unrelated_sessions(UserRole $role): void
    {
        config(['session.driver' => 'database', 'session.connection' => 'sqlite']);
        $user = User::factory()->create(['role' => $role, 'is_active' => true, 'remember_token' => 'previous-remember-token']);
        $other = $this->admin();
        $guard = Auth::guard('web');
        $ids = [];
        foreach ([$user, $user, $other] as $owner) {
            $id = Str::random(40);
            $ids[] = $id;
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->id, 'last_activity' => time(),
                'payload' => base64_encode(serialize([$guard->getName() => $owner->id, 'password_hash_web' => $owner->password]))]);
        }
        $handler = new DatabaseSessionHandler(DB::connection('sqlite'), 'sessions', 120, $this->app);
        $this->assertSame($user->id, unserialize($handler->read($ids[1]))[$guard->getName()]);
        Event::fake([OtherDeviceLogout::class]);
        $this->post(route('password.update'), ['email' => $user->email, 'token' => Password::createToken($user),
            'password' => 'Updated123', 'password_confirmation' => 'Updated123'])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('sessions', ['id' => $ids[0]]);
        $this->assertDatabaseMissing('sessions', ['id' => $ids[1]]);
        $this->assertDatabaseHas('sessions', ['id' => $ids[2], 'user_id' => $other->id]);
        $this->assertSame('', $handler->read($ids[1]));
        $this->assertTrue(Hash::check('Updated123', $user->fresh()->password));
        $this->assertNotSame('previous-remember-token', $user->fresh()->remember_token);
        $this->assertGuest();
        Event::assertDispatched(OtherDeviceLogout::class, fn ($event) => $event->user->id === $user->id);
    }

    #[DataProvider('roles')]
    public function test_authenticate_session_blocks_secondary_session_with_old_password_hash(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $oldHash = $user->password;
        $user->forceFill(['password' => 'Updated123'])->save();
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])
            ->get(route($role->value.'.home'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_invalid_reset_does_not_revoke_existing_sessions(): void
    {
        config(['session.driver' => 'database', 'session.connection' => 'sqlite']);
        $user = $this->admin();
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'last_activity' => time(), 'payload' => base64_encode(serialize([]))]);
        $this->post(route('password.update'), ['email' => $user->email, 'token' => 'invalid-token',
            'password' => 'Updated123', 'password_confirmation' => 'Updated123'])->assertSessionHas('reset_error');
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_guest_reset_with_stale_remember_cookie_does_not_issue_a_remembered_login(): void
    {
        $user = $this->admin();
        $name = Auth::guard('web')->getRecallerName();
        $this->withCookie($name, $user->id.'|obsolete-token|'.$user->password)
            ->post(route('password.update'), ['email' => $user->email, 'token' => Password::createToken($user),
                'password' => 'Updated123', 'password_confirmation' => 'Updated123'])
            ->assertRedirect(route('login'))->assertCookieMissing($name);
        $this->assertGuest();
        $this->assertNull(session(Auth::guard('web')->getName()));
    }

    public function test_service_fee_update_audits_authoritative_old_and_new_values_only(): void
    {
        $admin = $this->admin();
        $service = Service::create(['name' => 'Clearance', 'description' => 'Original', 'fee_amount' => '10.00', 'is_active' => true]);
        $this->actingAs($admin)->put(route('admin.services.update', $service), ['edit_revision' => app(ConfigurationAudit::class)->revision($service->fresh()), 'name' => 'Updated clearance', 'description' => 'New', 'fee_amount' => '25.50',
            'old_values' => ['fee_amount' => '999.00'], 'user_id' => 999, 'password' => 'not-audit-data'])->assertRedirect();
        $log = AuditLog::sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($service->id, $log->target_id);
        $this->assertSame('updated_service', $log->action);
        $this->assertSame(['name' => 'Clearance', 'description' => 'Original', 'fee_amount' => '10.00', 'is_active' => true], $log->old_values);
        $this->assertSame(['name' => 'Updated clearance', 'description' => 'New', 'fee_amount' => '25.50', 'is_active' => true], $log->new_values);
        $this->assertStringNotContainsString('not-audit-data', $log->toJson());
    }

    public function test_schedule_capacity_and_requirement_updates_are_audited(): void
    {
        $this->actingAs($this->admin());
        $schedule = Schedule::create(['date' => '2026-12-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5, 'is_active' => true]);
        $this->put(route('admin.schedules.update', $schedule), ['edit_revision' => app(ConfigurationAudit::class)->revision($schedule->fresh()), 'date' => '2026-12-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 8])->assertRedirect();
        $log = AuditLog::sole();
        $this->assertSame('updated_schedule', $log->action);
        $this->assertSame(5, $log->old_values['capacity']);
        $this->assertSame(8, $log->new_values['capacity']);
        $this->assertSame('2026-12-01', $log->old_values['date']);
        $service = Service::create(['name' => 'Clearance', 'fee_amount' => '0.00']);
        $requirement = $service->serviceRequirements()->create(['name' => 'ID', 'description' => 'Original', 'is_required' => true]);
        $this->put(route('admin.services.requirements.update', [$service, $requirement]), ['name' => 'Proof', 'description' => 'Revised', 'is_required' => false])->assertRedirect();
        $log = AuditLog::where('action', 'updated_requirement')->sole();
        $this->assertSame('ID', $log->old_values['name']);
        $this->assertTrue($log->old_values['is_required']);
        $this->assertSame('Proof', $log->new_values['name']);
        $this->assertFalse($log->new_values['is_required']);
    }

    public function test_audit_failure_rolls_back_the_configuration_change(): void
    {
        $this->actingAs($this->admin());
        $service = Service::create(['name' => 'Clearance', 'fee_amount' => '10.00']);
        $this->app->bind(ConfigurationAudit::class, fn () => new class extends ConfigurationAudit
        {
            public function record(User $actor, Model $target, string $operation, array $old, array $new): void
            {
                throw new RuntimeException('Simulated audit failure');
            }
        });
        $this->put(route('admin.services.update', $service), ['edit_revision' => app(ConfigurationAudit::class)->revision($service->fresh()), 'name' => 'Changed', 'fee_amount' => '20.00'])->assertStatus(500);
        $this->assertSame('Clearance', $service->fresh()->name);
        $this->assertSame('10.00', $service->fresh()->fee_amount);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_validation_and_noop_do_not_add_audit_records(): void
    {
        $this->actingAs($this->admin());
        $service = Service::create(['name' => 'Clearance', 'description' => null, 'fee_amount' => '10.00', 'is_active' => true]);
        $this->put(route('admin.services.update', $service), ['edit_revision' => app(ConfigurationAudit::class)->revision($service->fresh()), 'name' => 'Clearance', 'description' => null, 'fee_amount' => '10.00'])->assertSessionHasNoErrors();
        $this->put(route('admin.services.update', $service), ['edit_revision' => app(ConfigurationAudit::class)->revision($service->fresh()), 'name' => '', 'fee_amount' => '-1'])->assertSessionHasErrors();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_creation_activation_and_safe_requirement_deletion_are_audited(): void
    {
        $this->actingAs($this->admin())->post(route('admin.services.store'), ['name' => 'Clearance', 'fee_amount' => '0.00'])->assertRedirect();
        $service = Service::sole();
        $this->assertDatabaseHas('audit_logs', ['action' => 'created_service', 'target_id' => $service->id]);
        $this->patch(route('admin.services.status', $service), ['is_active' => false])->assertRedirect();
        $this->assertFalse(AuditLog::where('action', 'updated_service')->sole()->new_values['is_active']);
        $this->post(route('admin.services.requirements.store', $service), ['name' => 'Optional proof', 'is_required' => false])->assertRedirect();
        $requirement = $service->serviceRequirements()->sole();
        $this->delete(route('admin.services.requirements.destroy', [$service, $requirement]))->assertRedirect();
        $this->assertSame('Optional proof', AuditLog::where('action', 'deleted_requirement')->sole()->old_values['name']);
        $this->assertSame([], AuditLog::where('action', 'deleted_requirement')->sole()->new_values);
    }

    public function test_daily_housekeeping_is_registered_with_locks_and_timezone(): void
    {
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        foreach (['auth:clear-resets' => '10 2 * * *', 'smartbarangay:cleanup-document-previews' => '20 2 * * *'] as $command => $expression) {
            $event = collect($events)->first(fn ($event) => str_contains($event->command ?? '', $command));
            $this->assertNotNull($event);
            $this->assertSame($expression, $event->expression);
            $this->assertSame('Asia/Manila', $event->timezone);
            $this->assertTrue($event->withoutOverlapping);
        }
    }

    public function test_expired_reset_token_cleanup_preserves_live_tokens(): void
    {
        DB::table('password_reset_tokens')->insert([
            ['email' => 'expired@example.test', 'token' => 'old-hash', 'created_at' => now()->subMinutes(61)],
            ['email' => 'live@example.test', 'token' => 'live-hash', 'created_at' => now()->subMinutes(1)],
        ]);
        $this->artisan('auth:clear-resets')->assertSuccessful();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'expired@example.test']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'live@example.test']);
    }

    public function test_temporary_cleanup_preserves_recent_active_and_permanent_files(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $expired = 'document-previews/1/'.str_repeat('a', 40).'.pdf';
        $active = 'document-previews/2/'.str_repeat('b', 40).'.pdf';
        $recent = 'document-previews/3/'.str_repeat('c', 40).'.pdf';
        $permanent = 'generated-documents/permanent.pdf';
        $unknown = 'document-previews/unknown-file.pdf';
        foreach ([$expired, $active, $recent, $permanent, $unknown] as $path) {
            $disk->put($path, '%PDF-test');
            touch($disk->path($path), now()->subDays(2)->timestamp);
        }
        touch($disk->path($recent), now()->timestamp);
        Cache::put('document-preview:'.str_repeat('b', 40), ['path' => $active, 'expires' => now()->addMinutes(5)->timestamp], 600);
        $this->artisan('smartbarangay:cleanup-document-previews')->assertSuccessful();
        $disk->assertMissing($expired);
        foreach ([$active, $recent, $permanent, $unknown] as $path) {
            $disk->assertExists($path);
        }
    }

    public function test_audit_rows_are_not_editable(): void
    {
        $this->actingAs($this->admin())->post(route('admin.services.store'), ['name' => 'Clearance', 'fee_amount' => '0.00']);
        $this->expectException(LogicException::class);
        AuditLog::sole()->forceFill(['action' => 'rewritten'])->save();
    }
}
