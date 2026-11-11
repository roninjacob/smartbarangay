<?php

namespace Tests\Feature;

use App\Enums\ResidencyStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureResidencyApproved;
use App\Models\ResidencyReview;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ResidencyApprovalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Notification::fake();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function admin(array $values = []): User
    {
        return User::factory()->state(['role' => UserRole::Resident, 'is_active' => true])->create(['role' => UserRole::Admin, ...$values]);
    }

    private function decision(User $resident, string $status = 'approved', int $revision = 0): array
    {
        return ['residency_status' => $status, 'expected_residency_revision' => $revision,
            'resident_message' => $status === 'approved' ? null : 'Please review your recorded address.'];
    }

    public function test_registration_cannot_self_approve_or_verify(): void
    {
        $this->post(route('register.store'), ['name' => 'Synthetic Resident', 'email' => 'resident@example.test',
            'contact_number' => '09171234567', 'address' => 'Barangay Calayo',
            'password' => 'Resident123', 'password_confirmation' => 'Resident123',
            'residency_status' => 'approved', 'residency_reviewed_by' => 1, 'residency_revision' => 99])
            ->assertRedirect(route('verification.notice'));
        $user = User::sole();
        $this->assertSame(ResidencyStatus::Pending, $user->residency_status);
        $this->assertSame(0, $user->residency_revision);
        $this->assertNull($user->residency_reviewed_by);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public static function blockedStates(): array
    {
        return [['pending'], ['needs_correction'], ['rejected']];
    }

    #[DataProvider('blockedStates')]
    public function test_verified_unapproved_residents_keep_dashboard_profile_and_history_access(string $state): void
    {
        $resident = User::factory()->state(['role' => UserRole::Resident, 'is_active' => true])->create(['residency_status' => $state]);
        $this->actingAs($resident)->get(route('resident.home'))->assertOk()->assertSee($resident->residency_status->label());
        $this->get(route('resident.profile.edit'))->assertOk();
        $this->get(route('resident.reservations.index'))->assertOk();
        foreach ([['GET', '/resident/reservations/create'], ['POST', '/resident/reservations/service'],
            ['GET', '/resident/reservations/requirements'], ['POST', '/resident/reservations/requirements'],
            ['GET', '/resident/reservations/schedule'], ['POST', '/resident/reservations/schedule'],
            ['GET', '/resident/reservations/confirm'], ['POST', '/resident/reservations']] as [$method, $path]) {
            $this->call($method, $path)->assertForbidden();
        }
        $this->postJson('/resident/reservations', ['residency_status' => 'approved'])->assertForbidden()
            ->assertJsonPath('message', ResidencyStatus::BLOCKED_MESSAGE);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_admin_review_is_atomic_versioned_and_keeps_independent_account_fields(): void
    {
        $resident = User::factory()->state(['role' => UserRole::Resident, 'is_active' => true])->create();
        $admin = $this->admin();
        $before = $resident->refresh()->getRawOriginal();
        $this->actingAs($admin)->patch(route('admin.users.residency', $resident), $this->decision($resident))
            ->assertRedirect(route('admin.users.show', $resident));
        $resident->refresh();
        $this->assertSame(ResidencyStatus::Approved, $resident->residency_status);
        $this->assertSame(1, $resident->residency_revision);
        $this->assertSame($admin->id, $resident->residency_reviewed_by);
        $this->assertNotNull($resident->residency_reviewed_at);
        foreach (['password', 'role', 'is_active', 'email_verified_at'] as $key) {
            $this->assertSame($before[$key], $resident->getRawOriginal($key));
        }
        $review = ResidencyReview::sole();
        $this->assertSame($admin->id, $review->reviewed_by);
        $this->assertSame(ResidencyStatus::Pending, $review->from_status);
        $this->assertSame(ResidencyStatus::Approved, $review->to_status);
        $this->patch(route('admin.users.residency', $resident), $this->decision($resident, 'rejected', 0))
            ->assertSessionHasErrors('residency_status');
        $this->assertDatabaseCount('residency_reviews', 1);
        $this->get(route('admin.users.show', $resident))->assertOk()->assertSee('Residency decision history')->assertSee($admin->name);
        $this->get(route('admin.users.index', ['residency' => 'approved']))->assertOk()->assertSee($resident->email);
    }

    public function test_review_permissions_and_validation(): void
    {
        $resident = User::factory()->state(['role' => UserRole::Resident, 'is_active' => true])->create();
        $url = route('admin.users.residency', $resident);
        $this->patch($url, $this->decision($resident))->assertRedirect(route('login'));
        $this->actingAs($resident)->patch($url, $this->decision($resident))->assertForbidden();
        $this->actingAs($this->admin(['email_verified_at' => null]))->patch($url, $this->decision($resident))
            ->assertRedirect(route('verification.notice'));
        $this->actingAs($this->admin(['is_active' => false]))->patch($url, $this->decision($resident))->assertRedirect(route('login'));
        $admin = $this->admin();
        $this->actingAs($admin)->patch(route('admin.users.residency', $admin), $this->decision($admin))->assertNotFound();
        $this->patch($url, ['residency_status' => 'rejected', 'expected_residency_revision' => 0])->assertSessionHasErrors('resident_message');
        $this->patch($url, ['residency_status' => 'pending', 'expected_residency_revision' => 0])->assertSessionHasErrors('residency_status');
        $this->assertDatabaseCount('residency_reviews', 0);
    }

    public function test_corrections_and_rejections_remain_visible_and_only_admin_can_change_decisions(): void
    {
        $resident = User::factory()->state(['role' => UserRole::Resident, 'is_active' => true])->create();
        foreach (['needs_correction', 'rejected', 'approved'] as $revision => $status) {
            $this->actingAs($this->admin())->patch(route('admin.users.residency', $resident), $this->decision($resident, $status, $revision))
                ->assertSessionHasNoErrors();
            $resident->refresh();
            $this->actingAs($resident)->get(route('resident.profile.edit'))->assertOk()->assertSee($resident->residency_status->label());
        }
        $this->put(route('resident.profile.update'), ['name' => $resident->name, 'residency_status' => 'approved'])
            ->assertSessionHasErrors('residency_status');
        $this->assertDatabaseCount('residency_reviews', 3);
    }

    private function draft(User $resident): string
    {
        $service = Service::create(['name' => 'Synthetic Clearance', 'fee_amount' => '0.00']);
        $schedule = Schedule::create(['date' => now('Asia/Manila')->addDays(3)->toDateString(),
            'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5]);
        $this->actingAs($resident)->post(route('resident.reservations.service'), ['service_id' => $service->id])->assertSessionHasNoErrors();
        $this->post(route('resident.reservations.requirements.review'), ['requirement_signature' => $this->requirementSignature()])->assertSessionHasNoErrors();
        $this->post(route('resident.reservations.schedule.select'), ['schedule_id' => $schedule->id])->assertSessionHasNoErrors();

        return session('reservation_wizard.confirmation_token');
    }

    public function test_admin_approved_resident_can_complete_normal_submission(): void
    {
        $resident = User::factory()->state(['role' => UserRole::Resident, 'is_active' => true])->create();
        $this->actingAs($this->admin())->patch(route('admin.users.residency', $resident), $this->decision($resident))->assertSessionHasNoErrors();
        $token = $this->draft($resident->fresh());
        $this->post(route('resident.reservations.store'), ['confirmation_token' => $token])->assertRedirect(route('resident.home'));
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_locked_submission_rechecks_approval_after_route_middleware(): void
    {
        $resident = User::factory()->state(['role' => UserRole::Resident, 'is_active' => true])->residencyApproved()->create();
        $token = $this->draft($resident);
        $this->app->bind(EnsureResidencyApproved::class, fn () => new class extends EnsureResidencyApproved
        {
            public function handle($request, \Closure $next): Response
            {
                return parent::handle($request, function ($request) use ($next) {
                    DB::table('users')->where('id', $request->user()->id)->update(['residency_status' => 'pending']);

                    return $next($request);
                });
            }
        });
        $this->post(route('resident.reservations.store'), ['confirmation_token' => $token])->assertForbidden();
        foreach (['reservations', 'reservation_attachments', 'qr_tickets', 'notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_additive_migration_preserves_legacy_users_and_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_11_000034_add_residency_approval.php');
        $migration->down();
        $values = ['name' => 'Legacy resident', 'email' => 'legacy@example.test', 'password' => 'existing-hash',
            'role' => 'resident', 'is_active' => 1, 'email_verified_at' => '2026-01-01 00:00:00'];
        $id = DB::table('users')->insertGetId($values);
        $migration->up();
        $row = DB::table('users')->find($id);
        foreach ($values as $key => $value) {
            $this->assertEquals($value, $row->$key);
        }
        $this->assertSame('pending', $row->residency_status);
        $this->assertSame(0, $row->residency_revision);
        $migration->down();
        $this->assertSame('existing-hash', DB::table('users')->find($id)->password);
    }

    public function test_cross_device_verification_requires_login_and_original_browser_sees_database_change(): void
    {
        $resident = User::factory()->state(['role' => UserRole::Resident, 'is_active' => true])->unverified()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $resident->id, 'hash' => sha1($resident->email)]);
        $this->get($link)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse($resident->fresh()->hasVerifiedEmail());
        $this->post(route('login.store'), ['email' => $resident->email, 'password' => 'password'])->assertRedirect($link);
        $this->get($link)->assertRedirect(route('resident.home'));
        // A different session can still hold the pre-verification User instance.
        $this->actingAs($resident)->getJson(route('verification.status'))->assertOk()
            ->assertJsonPath('verified', true)->assertJsonPath('redirect', route('resident.home'))
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('verification.notice'))->assertRedirect(route('resident.home'));
        $this->post(route('verification.send'))->assertRedirect(route('resident.home'));
        $this->get(route('resident.home'))->assertOk();
        $this->assertSame(ResidencyStatus::Pending, $resident->fresh()->residency_status);
    }

    public function test_verification_status_is_private_authenticated_and_throttled(): void
    {
        $this->getJson(route('verification.status'))->assertUnauthorized();
        $resident = User::factory()->state(['role' => UserRole::Resident, 'is_active' => true])->unverified()->create();
        $this->actingAs($resident)->getJson(route('verification.status'))->assertOk()
            ->assertExactJson(['verified' => false, 'redirect' => null]);
        for ($i = 0; $i < 11; $i++) {
            $this->getJson(route('verification.status'))->assertOk();
        }
        $this->getJson(route('verification.status'))->assertStatus(429);
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $resident->id, 'hash' => sha1($resident->email)]);
        $this->get($link)->assertRedirect(route('resident.home'));
    }

    public function test_review_messages_are_escaped_and_reviewed_unverified_accounts_survive_cleanup(): void
    {
        $resident = User::factory()->state(['role' => UserRole::Resident, 'is_active' => true])->unverified()
            ->create(['created_at' => now()->subDays(10)]);
        $message = '<script>alert("untrusted")</script>';
        $this->actingAs($this->admin())->patch(route('admin.users.residency', $resident), [
            ...$this->decision($resident, 'needs_correction'), 'resident_message' => $message,
            'role' => 'admin', 'is_active' => false,
        ])->assertSessionHasNoErrors();
        $this->get(route('admin.users.show', $resident))->assertOk()->assertSee($message)->assertDontSee($message, false);
        $this->assertTrue($resident->fresh()->is_active);
        $this->assertSame(UserRole::Resident, $resident->fresh()->role);
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--dry-run' => true])
            ->expectsOutputToContain('eligible: 0')->assertSuccessful();
        $this->assertDatabaseHas('users', ['id' => $resident->id]);
    }

    public function test_residency_review_writes_roll_back_when_history_insert_fails(): void
    {
        $resident = User::factory()->state(['role' => UserRole::Resident, 'is_active' => true])->create();
        ResidencyReview::creating(fn () => throw new \RuntimeException('Synthetic history failure'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->admin())->patch(route('admin.users.residency', $resident), $this->decision($resident));
            $this->fail('The synthetic history failure must abort the decision.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic history failure', $exception->getMessage());
        } finally {
            ResidencyReview::flushEventListeners();
        }
        $this->assertSame(ResidencyStatus::Pending, $resident->fresh()->residency_status);
        $this->assertSame(0, $resident->fresh()->residency_revision);
        $this->assertDatabaseCount('residency_reviews', 0);
    }
}
