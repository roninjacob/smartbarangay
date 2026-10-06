<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CheckinLog;
use App\Models\QrTicket;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;

class UnverifiedAccountCleanupTest extends TestCase
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
        $this->travelTo(now()->startOfSecond());
        Notification::fake();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function resident(int $days = 8, bool $verified = false): User
    {
        return User::factory()->create(['created_at' => now()->subDays($days), 'email_verified_at' => $verified ? now() : null])->refresh();
    }

    private function reservation(User $user): Reservation
    {
        $service = Service::create(['name' => 'Barangay Clearance']);
        $schedule = Schedule::create(['date' => now()->addDays(2)->toDateString(), 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 10]);
        return Reservation::create(['user_id' => $user->id, 'service_id' => $service->id, 'schedule_id' => $schedule->id]);
    }

    public function test_only_old_unverified_residents_are_deleted_at_the_exact_seven_day_boundary(): void
    {
        $old = $this->resident();
        $exact = $this->resident(7);
        $recent = $this->resident(0);
        $justBefore = $this->resident(7);
        $justBefore->forceFill(['created_at' => now()->subDays(7)->addSecond()])->save();
        $verified = $this->resident(30, true);
        $admin = $this->resident(30);
        $admin->forceFill(['role' => UserRole::Admin])->save();
        $newAdmin = $this->resident(0);
        $newAdmin->forceFill(['role' => UserRole::Admin])->save();

        $this->artisan('smartbarangay:cleanup-unverified-users')
            ->expectsOutput('Candidates: 2; eligible: 2; removed: 2; skipped: 0.')->assertSuccessful();
        foreach ([$old, $exact] as $user) {
            $this->assertDatabaseMissing('users', ['id' => $user->id]);
        }
        foreach ([$recent, $justBefore, $verified, $admin, $newAdmin] as $user) {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
        }
    }

    public function test_transaction_and_audit_records_protect_residents_and_log_skip_reasons(): void
    {
        Log::spy();
        $owner = $this->resident();
        $reservation = $this->reservation($owner);
        $ticket = QrTicket::create(['reservation_id' => $reservation->id, 'ticket_code' => Str::uuid(), 'qr_payload' => Str::uuid(), 'generated_at' => now()]);
        $verifier = $this->resident();
        $log = CheckinLog::create(['verified_by' => $verifier->id, 'verification_result' => 'invalid', 'verified_at' => now()]);
        $actor = $this->resident();
        $history = ReservationStatusHistory::create(['reservation_id' => $reservation->id, 'changed_by' => $actor->id, 'to_status' => 'pending', 'changed_at' => now()]);

        $this->artisan('smartbarangay:cleanup-unverified-users')
            ->expectsOutput('Candidates: 3; eligible: 0; removed: 0; skipped: 3.')->assertSuccessful();
        foreach ([$owner, $verifier, $actor] as $user) {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
            Log::shouldHaveReceived('info')->with('Unverified Resident cleanup account result.', [
                'user_id' => $user->id, 'result' => 'business_records', 'dry_run' => false,
            ])->once();
        }
        $this->assertDatabaseHas('qr_tickets', ['id' => $ticket->id]);
        $this->assertSame($verifier->id, $log->fresh()->verified_by);
        $this->assertSame($actor->id, $history->fresh()->changed_by);
    }

    public function test_dry_run_reports_safe_accounts_without_modifying_users_or_authentication_artifacts(): void
    {
        $user = $this->resident();
        $protected = $this->resident();
        $this->reservation($protected);
        Password::createToken($user);
        DB::table('sessions')->insert(['id' => 'abandoned-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--dry-run' => true])
            ->expectsOutput('Dry run: no database records were modified.')
            ->expectsOutput('Candidates: 2; eligible: 1; removed: 0; skipped: 1.')->assertSuccessful();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertDatabaseCount('sessions', 1);
    }

    public function test_cleanup_removes_only_deleted_users_authentication_artifacts(): void
    {
        $user = $this->resident();
        $kept = $this->resident(0);
        Password::createToken($user);
        Password::createToken($kept);
        foreach ([$user, $kept] as $account) {
            DB::table('sessions')->insert(['id' => 'session-'.$account->id, 'user_id' => $account->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        }
        $this->artisan('smartbarangay:cleanup-unverified-users')->assertSuccessful();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $kept->email]);
        $this->assertDatabaseHas('sessions', ['user_id' => $kept->id]);
    }

    public function test_successful_verification_immediately_excludes_an_old_resident(): void
    {
        $user = $this->resident(8);
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($link)->assertRedirect('/resident/home');
        $this->artisan('smartbarangay:cleanup-unverified-users')
            ->expectsOutput('Candidates: 0; eligible: 0; removed: 0; skipped: 0.')->assertSuccessful();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_cleanup_rechecks_a_candidate_that_became_verified_after_scanning(): void
    {
        $user = $this->resident();
        Event::listen('eloquent.retrieved: '.User::class, function (User $candidate) use ($user) {
            if ($candidate->id === $user->id && array_keys($candidate->getAttributes()) === ['id']) {
                User::whereKey($user->id)->update(['email_verified_at' => now()]);
            }
        });
        $this->artisan('smartbarangay:cleanup-unverified-users')
            ->expectsOutput('Candidates: 1; eligible: 0; removed: 0; skipped: 1.')->assertSuccessful();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_chunking_processes_more_than_one_batch_without_skipping_accounts(): void
    {
        User::factory()->count(205)->create(['created_at' => now()->subDays(8), 'email_verified_at' => null]);
        $this->artisan('smartbarangay:cleanup-unverified-users')
            ->expectsOutput('Candidates: 205; eligible: 205; removed: 205; skipped: 0.')->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_email_correction_preserves_registration_age_and_invalidates_old_verification_links(): void
    {
        $user = $this->resident(6);
        $createdAt = $user->created_at->toDateTimeString();
        $oldLink = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get('/email/verify')->assertSee('7 days after registration.')->assertSee('Change Email Address');
        $this->get('/email/change')->assertOk()->assertSee('Current Password');
        $this->put('/email/change', ['email' => ' Corrected@Example.com ', 'current_password' => 'password'])
            ->assertRedirect(route('verification.notice'))->assertSessionHas('status');
        $this->assertSame('corrected@example.com', $user->fresh()->email);
        $this->assertSame($createdAt, $user->fresh()->created_at->toDateTimeString());
        $this->assertNull($user->fresh()->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get('/email/verify')->assertSee('corrected@example.com');
        $this->get($oldLink)->assertForbidden();
        $this->put('/email/change', ['email' => 'other@example.com', 'current_password' => 'password'])->assertStatus(429);
        $this->travel(2)->days();
        $this->artisan('smartbarangay:cleanup-unverified-users')->assertSuccessful();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_email_correction_requires_password_unique_email_and_eligible_resident(): void
    {
        $user = $this->resident(0);
        $other = $this->resident(0);
        $this->actingAs($user)->put('/email/change', ['email' => 'new@example.com', 'current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password')->assertSessionMissing('_old_input.current_password');
        $this->travel(61)->seconds();
        $this->put('/email/change', ['email' => $other->email, 'current_password' => 'password'])->assertSessionHasErrors('email');
        $this->assertNotSame('new@example.com', $user->fresh()->email);
        Notification::assertNothingSent();
        $this->travel(61)->seconds();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user->fresh())->put('/email/change', ['email' => 'new@example.com', 'current_password' => 'password'])->assertForbidden();
        $this->get('/email/change')->assertRedirect('/resident/home');
        $admin = $this->resident(0);
        $admin->forceFill(['role' => UserRole::Admin])->save();
        $this->actingAs($admin)->get('/email/change')->assertRedirect(route('verification.notice'));
        $this->get('/email/verify')->assertOk()->assertDontSee('Change Email Address');
        $this->put('/email/change', ['email' => 'admin-new@example.com', 'current_password' => 'password'])->assertForbidden();
        $this->travel(61)->seconds();
        $admin->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($admin->fresh())->put('/email/change', ['email' => 'admin-new@example.com', 'current_password' => 'password'])->assertForbidden();
        $this->get('/email/change')->assertRedirect('/admin/home');
        $this->post('/logout');
        $this->put('/email/change', [])->assertRedirect(route('login'));
    }

    public function test_cleanup_is_scheduled_daily_with_overlap_protection(): void
    {
        $events = collect($this->app->make(LaravelSchedule::class)->events());
        $cleanup = $events->first(fn ($event) => str_contains($event->command ?? '', 'smartbarangay:cleanup-unverified-users'));
        $this->assertNotNull($cleanup);
        $this->assertSame('0 2 * * *', $cleanup->expression);
        $this->assertSame('Asia/Manila', $cleanup->timezone);
        $this->assertTrue($cleanup->withoutOverlapping);
    }

    public function test_email_delivery_failure_preserves_the_corrected_email_and_original_age(): void
    {
        $user = $this->resident(6);
        $createdAt = $user->created_at->toDateTimeString();
        Notification::shouldReceive('send')->once()->andThrow(new TransportException('Simulated SMTP failure'));
        $this->actingAs($user)->put('/email/change', ['email' => 'corrected@example.com', 'current_password' => 'password'])
            ->assertRedirect(route('verification.notice'))->assertSessionHas('warning');
        $this->assertSame('corrected@example.com', $user->fresh()->email);
        $this->assertSame($createdAt, $user->fresh()->created_at->toDateTimeString());
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_correction_is_csrf_protected_and_inactive_users_cannot_use_it(): void
    {
        $user = $this->resident(0);
        $user->forceFill(['is_active' => false])->save();
        $this->actingAs($user)->put('/email/change', ['email' => 'corrected@example.com', 'current_password' => 'password'])
            ->assertRedirect(route('login'));
        $this->assertGuest();
        $user->forceFill(['is_active' => true])->save();
        $this->app['env'] = 'local';
        $this->actingAs($user)->put('/email/change', ['email' => 'corrected@example.com', 'current_password' => 'password'])->assertStatus(419);
        $this->assertNotSame('corrected@example.com', $user->fresh()->email);
    }

    public function test_change_and_subsequent_resend_use_only_the_new_email_and_preserve_protected_fields(): void
    {
        $role = UserRole::Resident;
        $user = $this->resident(6);
        $user->forceFill(['role' => $role])->save();
        $originalEmail = $user->email;
        $createdAt = $user->created_at->toDateTimeString();
        $this->actingAs($user)->get('/email/change')->assertOk()->assertSee('New Email Address')->assertSee('Current Password');
        $this->put('/email/change', [
            'email' => ' Corrected@Example.com ', 'current_password' => 'password',
            'role' => $role === UserRole::Resident ? 'admin' : 'resident',
            'is_active' => false, 'email_verified_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ])->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'Your email address has been updated. A new verification link has been sent.');
        $user->refresh();
        $this->assertSame('corrected@example.com', $user->email);
        $this->assertSame($role, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->email_verified_at);
        $this->assertSame($createdAt, $user->created_at->toDateTimeString());
        Notification::assertSentTo($user, VerifyEmail::class, fn ($notification, $channels, $notifiable) =>
            $notifiable->routeNotificationFor('mail', $notification) === 'corrected@example.com');
        $this->get('/email/verify')->assertSee('corrected@example.com')->assertDontSee($originalEmail);
        $this->get(route($user->homeRouteName()))->assertRedirect(route('verification.notice'));

        Notification::fake();
        $this->travel(61)->seconds();
        $this->post('/email/verification-notification')->assertRedirect(route('verification.notice'));
        Notification::assertSentTo($user, VerifyEmail::class, fn ($notification, $channels, $notifiable) =>
            $notifiable->routeNotificationFor('mail', $notification) === 'corrected@example.com');
        $notification = Notification::sent($user, VerifyEmail::class)->sole();
        $link = $notification->toMail($user)->actionUrl;
        $this->assertStringContainsString(sha1('corrected@example.com'), $link);
        $this->assertStringNotContainsString(sha1($originalEmail), $link);
        $this->get($link)->assertRedirect(route($user->homeRouteName()));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_invalid_email_values_cannot_change_the_account(): void
    {
        $user = $this->resident(0);
        $originalEmail = $user->email;
        $this->actingAs($user);
        foreach (['invalid-email', ['email' => 'other@example.com'], $originalEmail] as $email) {
            $this->put('/email/change', ['email' => $email, 'current_password' => 'password'])->assertSessionHasErrors('email');
            $this->assertSame($originalEmail, $user->fresh()->email);
            $this->travel(61)->seconds();
        }
        Notification::assertNothingSent();
    }
}
