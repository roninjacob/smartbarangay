<?php

namespace Tests\Feature;

use App\Enums\ResidencyStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Models\CheckinLog;
use App\Models\QrTicket;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

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

        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])
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

        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])
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
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])->assertSuccessful();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $kept->email]);
        $this->assertDatabaseHas('sessions', ['user_id' => $kept->id]);
    }

    public function test_successful_verification_immediately_excludes_an_old_resident(): void
    {
        $user = $this->resident(8);
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($link)->assertRedirect(route('resident.home'));
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])
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
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])
            ->expectsOutput('Candidates: 1; eligible: 0; removed: 0; skipped: 1.')->assertSuccessful();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_chunking_processes_more_than_one_batch_without_skipping_accounts(): void
    {
        User::factory()->count(205)->create(['created_at' => now()->subDays(8), 'email_verified_at' => null]);
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])
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
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])->assertSuccessful();
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
        $this->assertStringContainsString('--dry-run', $cleanup->command);
        $this->assertStringNotContainsString('--execute', $cleanup->command);
    }

    private function registerProfileOnly(): User
    {
        $this->post(route('register.store'), [
            'first_name' => 'Synthetic', 'middle_name' => null, 'last_name' => 'Resident',
            'suffix' => null, 'birthdate' => '2000-01-01', 'contact_number' => '09171234567',
            'house_unit_number' => '12', 'address_street' => 'Sample Street', 'address_zone_purok' => 'Purok 1',
            'email' => 'abandoned@example.test', 'password' => 'Resident123!', 'password_confirmation' => 'Resident123!',
        ])->assertSessionHasNoErrors()->assertRedirect(route('verification.notice'));

        return User::where('email', 'abandoned@example.test')->sole();
    }

    public function test_registration_profile_is_eligible_after_retention_and_default_execution_is_read_only(): void
    {
        $user = $this->registerProfileOnly();
        $this->artisan('smartbarangay:cleanup-unverified-users')->expectsOutputToContain('eligible: 0')->assertSuccessful();
        $this->travel(7)->days();
        $this->artisan('smartbarangay:cleanup-unverified-users')
            ->expectsOutput('Dry run: no database records were modified.')
            ->expectsOutputToContain('eligible: 1; removed: 0')->assertSuccessful();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('resident_profiles', ['user_id' => $user->id]);
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])
            ->expectsOutputToContain('eligible: 1; removed: 1')->assertSuccessful();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('resident_profiles', ['user_id' => $user->id]);
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])
            ->expectsOutput('Candidates: 0; eligible: 0; removed: 0; skipped: 0.')->assertSuccessful();
    }

    public function test_cleanup_releases_email_without_transferring_identity_or_old_verification_links(): void
    {
        $user = $this->registerProfileOnly();
        $oldLink = URL::temporarySignedRoute('verification.verify', now()->addDays(10), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->post('/logout');
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->travel(8)->days();
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])->assertSuccessful();
        $this->post('/logout');
        $this->post(route('password.email'), ['email' => 'unknown@example.test'])
            ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);
        $replacement = $this->registerProfileOnly();
        $this->assertNotSame($user->id, $replacement->id);
        $this->assertNull($replacement->email_verified_at);
        $this->assertSame(ResidencyStatus::Pending, $replacement->residency_status);
        $this->get($oldLink)->assertForbidden();
        $this->assertNull($replacement->fresh()->email_verified_at);
    }

    public function test_profile_only_data_is_removed_but_verification_and_residency_evidence_are_preserved(): void
    {
        $eligible = $this->resident();
        $eligible->profile()->make()->forceFill(['birthdate' => '2000-01-01', 'occupation' => 'Synthetic occupation'])->save();
        $protected = [];
        foreach ([['email_verified_at' => now()], ['residency_status' => 'approved'],
            ['residency_status' => 'needs_correction'], ['residency_status' => 'rejected'],
            ['residency_revision' => 1], ['residency_reviewed_at' => now()], ['is_active' => false]] as $state) {
            $user = $this->resident();
            $user->forceFill($state)->save();
            $user->profile()->make()->forceFill(['birthdate' => '2000-01-01'])->save();
            $protected[] = $user;
        }
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])->assertSuccessful();
        $this->assertDatabaseMissing('users', ['id' => $eligible->id]);
        foreach ($protected as $user) {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
            $this->assertDatabaseHas('resident_profiles', ['user_id' => $user->id]);
        }
    }

    public function test_review_history_and_material_change_audit_protect_even_pending_unverified_accounts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $reviewed = $this->resident();
        DB::table('residency_reviews')->insert(['user_id' => $reviewed->id, 'reviewed_by' => $admin->id,
            'from_status' => 'approved', 'to_status' => 'pending', 'reviewed_at' => now()]);
        $audited = $this->resident();
        DB::table('audit_logs')->insert(['user_id' => $admin->id, 'action' => 'residency_profile_changed',
            'target_id' => $audited->id, 'old_values' => '{}', 'new_values' => '{}', 'created_at' => now()]);
        $accountReviewed = $this->resident();
        DB::table('audit_logs')->insert(['user_id' => $admin->id, 'action' => 'updated_resident_account',
            'target_id' => $accountReviewed->id, 'old_values' => '{}', 'new_values' => '{}', 'created_at' => now()]);
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])
            ->expectsOutputToContain('eligible: 0; removed: 0; skipped: 3')->assertSuccessful();
        $this->assertDatabaseHas('users', ['id' => $reviewed->id]);
        $this->assertDatabaseHas('users', ['id' => $audited->id]);
        $this->assertDatabaseHas('users', ['id' => $accountReviewed->id]);
        $this->assertDatabaseCount('residency_reviews', 1);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_payment_document_and_qr_actor_attribution_survives_cleanup(): void
    {
        $owner = $this->resident(0, true);
        $reservation = $this->reservation($owner);
        $paymentActor = $this->resident();
        $documentActor = $this->resident();
        $qrActor = $this->resident();
        DB::table('reservation_payments')->insert(['reservation_id' => $reservation->id, 'amount' => '10.00',
            'paid_at' => now(), 'recorded_by' => $paymentActor->id]);
        DB::table('reservation_documents')->insert(['reservation_id' => $reservation->id, 'template_revision' => str_repeat('a', 64),
            'template_filename' => 'Synthetic.pdf', 'original_filename' => 'Synthetic.pdf', 'stored_path' => 'synthetic-unread-file',
            'mime_type' => 'application/pdf', 'file_size' => 1, 'generated_by' => $documentActor->id, 'generated_at' => now()]);
        DB::table('qr_tickets')->insert(['reservation_id' => $reservation->id, 'ticket_code' => (string) Str::uuid(),
            'qr_payload' => (string) Str::uuid(), 'generated_at' => now(), 'used_at' => now(), 'used_by' => $qrActor->id]);
        $before = [];
        foreach (['reservations', 'reservation_payments', 'reservation_documents', 'qr_tickets'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])->assertSuccessful();
        foreach ([$owner, $paymentActor, $documentActor, $qrActor] as $user) {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
        }
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson());
        }
    }

    public function test_admin_review_between_candidate_scan_and_locked_recheck_prevents_deletion(): void
    {
        $user = $this->resident();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Event::listen('eloquent.retrieved: '.User::class, function (User $candidate) use ($user, $admin) {
            if ($candidate->id === $user->id && array_keys($candidate->getAttributes()) === ['id']) {
                DB::table('residency_reviews')->insert(['user_id' => $user->id, 'reviewed_by' => $admin->id,
                    'from_status' => 'pending', 'to_status' => 'approved', 'reviewed_at' => now()]);
                User::whereKey($user->id)->update(['residency_status' => 'approved', 'residency_revision' => 1]);
            }
        });
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])->expectsOutputToContain('removed: 0')->assertSuccessful();
        $this->assertSame(ResidencyStatus::Approved, $user->fresh()->residency_status);
        $this->assertDatabaseCount('residency_reviews', 1);
    }

    public function test_unknown_restricting_business_reference_rolls_back_profile_and_authentication_deletion(): void
    {
        $user = $this->resident();
        $user->profile()->make()->forceFill(['birthdate' => '2000-01-01'])->save();
        Password::createToken($user);
        DB::table('sessions')->insert(['id' => 'protected-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        DB::statement('CREATE TABLE synthetic_protected_history (user_id INTEGER REFERENCES users(id) ON DELETE RESTRICT)');
        DB::table('synthetic_protected_history')->insert(['user_id' => $user->id]);
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])
            ->expectsOutputToContain('removed: 0; skipped: 1')->assertSuccessful();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('resident_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertDatabaseCount('sessions', 1);
        $this->assertDatabaseCount('synthetic_protected_history', 1);
    }

    public function test_conflicting_execution_flags_are_rejected_without_deleting_anything(): void
    {
        $user = $this->resident();
        $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true, '--dry-run' => true])->assertFailed();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_both_modes_request_the_parent_user_lock_inside_the_transaction(): void
    {
        $user = $this->resident();
        $grammar = new class(DB::connection()) extends SQLiteGrammar
        {
            public bool $lockedUserRead = false;

            public function compileSelect(Builder $query)
            {
                if ($query->from === 'users' && $query->lock === true && $query->getConnection()->transactionLevel() > 0) {
                    $this->lockedUserRead = true;
                }

                return parent::compileSelect($query);
            }
        };
        DB::connection()->setQueryGrammar($grammar);
        foreach (['--dry-run', '--execute'] as $mode) {
            $grammar->lockedUserRead = false;
            $this->artisan('smartbarangay:cleanup-unverified-users', [$mode => true])->assertSuccessful();
            $this->assertTrue($grammar->lockedUserRead);
        }
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_cleanup_queries_and_logs_exclude_personal_values_and_credentials(): void
    {
        $user = $this->registerProfileOnly();
        $this->travel(8)->days();
        Password::createToken($user);
        Log::spy();
        DB::connection()->enableQueryLog();
        try {
            $this->artisan('smartbarangay:cleanup-unverified-users', ['--execute' => true])->assertSuccessful();
            foreach (DB::connection()->getQueryLog() as $query) {
                foreach ([$user->email, $user->name, '2000-01-01', 'Sample Street'] as $privateValue) {
                    $this->assertNotContains($privateValue, $query['bindings']);
                }
            }
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }
        Log::shouldHaveReceived('info')->with('Unverified Resident cleanup account result.', [
            'user_id' => $user->id, 'result' => 'removed', 'dry_run' => false,
        ])->once();
        Log::shouldHaveReceived('info')->with('Unverified Resident cleanup completed.', [
            'candidates' => 1, 'eligible' => 1, 'removed' => 1, 'skipped' => 0, 'dry_run' => false,
        ])->once();
        Log::shouldHaveReceived('info')->twice();
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
        Notification::assertSentTo($user, VerifyEmail::class, fn ($notification, $channels, $notifiable) => $notifiable->routeNotificationFor('mail', $notification) === 'corrected@example.com');
        $this->get('/email/verify')->assertSee('corrected@example.com')->assertDontSee($originalEmail);
        $this->get(route($user->homeRouteName()))->assertRedirect(route('verification.notice'));

        Notification::fake();
        $this->travel(61)->seconds();
        $this->post('/email/verification-notification')->assertRedirect(route('verification.notice'));
        Notification::assertSentTo($user, VerifyEmail::class, fn ($notification, $channels, $notifiable) => $notifiable->routeNotificationFor('mail', $notification) === 'corrected@example.com');
        $notification = Notification::sent($user, VerifyEmail::class)->sole();
        $link = $notification->toMail($user)->actionUrl;
        $this->assertStringContainsString(sha1('corrected@example.com'), $link);
        $this->assertStringNotContainsString(sha1($originalEmail), $link);
        $this->get($link)->assertRedirect(route('resident.home'));
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
