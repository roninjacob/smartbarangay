<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;

class AuthenticationAdditionsTest extends TestCase
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
        Notification::fake();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public static function roles(): array
    {
        return [[UserRole::Resident, '/resident/home'], [UserRole::Admin, '/admin/home']];
    }

    private function user(UserRole $role = UserRole::Resident, bool $verified = false, bool $active = true): User
    {
        $user = User::factory()->make(['email_verified_at' => $verified ? now() : null]);
        $user->role = $role;
        $user->is_active = $active;
        $user->save();
        return $user;
    }

    private function signedLink(User $user, int $minutes = 60, array $overrides = []): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
            'id' => $user->id, 'hash' => sha1($user->email), ...$overrides,
        ]);
    }

    #[DataProvider('roles')]
    public function test_both_roles_require_verification_and_can_resend_before_dashboard_access(UserRole $role, string $home): void
    {
        $user = $this->user($role);
        $destination = route('verification.notice');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($destination);
        foreach (['/', '/login', '/register'] as $path) {
            $this->get($path)->assertRedirect($destination);
        }
        $this->get($home)->assertRedirect($destination);
        $notice = $this->get('/email/verify')->assertOk()->assertSee($user->email)
            ->assertSee('Resend Verification Email')->assertSee('Logout');
        if ($role === UserRole::Admin) {
            $notice->assertDontSee('Change Email Address')->assertDontSee('7 days after registration');
        }
        $this->post('/email/verification-notification')->assertRedirect($destination);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get($role === UserRole::Admin ? '/resident/home' : '/admin/home')->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    #[DataProvider('roles')]
    public function test_valid_signed_link_verifies_and_redirects_to_correct_role(UserRole $role, string $home): void
    {
        Event::fake([Verified::class]);
        $user = $this->user($role);
        $link = $this->signedLink($user);
        $this->actingAs($user)->get($link)->assertRedirect($home)->assertSessionHas('status', 'Email verified successfully.');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class, fn ($event) => $event->user->id === $user->id);
        $this->get($home)->assertOk()->assertSee('Email verified successfully.');
        $this->get('/email/verify')->assertRedirect($home);
        // Opening an already-used valid verification link is idempotent.
        $this->get($link)->assertRedirect($home);
        Event::assertDispatchedTimes(Verified::class, 1);
    }

    #[DataProvider('roles')]
    public function test_tampered_expired_and_mismatched_verification_links_never_verify(UserRole $role, string $home): void
    {
        $user = $this->user($role);
        $other = $this->user($role);
        $this->actingAs($user);
        foreach ([
            $this->signedLink($user).'tampered',
            $this->signedLink($user, -1),
            $this->signedLink($user, 60, ['hash' => str_repeat('0', 40)]),
            $this->signedLink($other),
        ] as $link) {
            $this->get($link)->assertForbidden()->assertSee('This verification link is invalid or has expired.')
                ->assertSee('Request New Verification Email')->assertDontSee('Stack trace');
            $this->assertFalse($user->fresh()->hasVerifiedEmail());
            $this->assertFalse($other->fresh()->hasVerifiedEmail());
        }
    }

    public function test_verification_routes_require_authentication(): void
    {
        $user = $this->user();
        $this->get('/email/verify')->assertRedirect(route('login'));
        $this->get($this->signedLink($user))->assertRedirect(route('login'));
        $this->post('/email/verification-notification')->assertRedirect(route('login'));
        Notification::assertNothingSent();
    }

    public function test_resend_is_throttled_on_server_and_verified_users_do_not_receive_duplicates(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/email/verification-notification')
            ->assertRedirect(route('verification.notice'))->assertSessionHas('status');
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
        $this->post('/email/verification-notification')->assertStatus(429)
            ->assertSee('Please wait before requesting another verification email.')->assertHeader('Retry-After');
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
        $this->travel(61)->seconds();
        $this->post('/email/verification-notification')->assertRedirect(route('verification.notice'));
        Notification::assertSentToTimes($user, VerifyEmail::class, 2);
        $verified = $this->user(UserRole::Admin, true);
        $this->actingAs($verified)->post('/email/verification-notification')->assertRedirect('/admin/home');
        Notification::assertNotSentTo($verified, VerifyEmail::class);
    }

    #[DataProvider('roles')]
    public function test_inactive_accounts_cannot_verify_or_resend(UserRole $role, string $home): void
    {
        $user = $this->user($role, false, false);
        $this->actingAs($user)->get($this->signedLink($user))->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => EnsureAccountIsActive::MESSAGE]);
        $this->assertGuest();
        $this->actingAs($user)->post('/email/verification-notification')->assertRedirect(route('login'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Notification::assertNothingSent();
    }

    public function test_password_request_pages_and_public_responses_do_not_reveal_account_existence(): void
    {
        $this->get('/login')->assertSee('Forgot Password?')->assertSee(route('password.request'));
        $this->get('/forgot-password')->assertOk()->assertSee('Email Address')->assertSee('Send Password Reset Link');
        $user = $this->user(UserRole::Resident, true);
        foreach ([$user->email, 'unknown@example.com'] as $email) {
            $this->post('/forgot-password', ['email' => $email])->assertRedirect(route('password.request'))
                ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE)->assertSessionHasNoErrors();
            $this->get('/forgot-password')->assertOk()->assertSee('Check your email')->assertDontSee($email);
        }
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
    }

    public function test_password_email_requests_are_throttled_for_known_and_unknown_addresses(): void
    {
        foreach ([$this->user()->email, 'unknown@example.com'] as $email) {
            $this->post('/forgot-password', ['email' => $email])->assertRedirect(route('password.request'));
            $this->post('/forgot-password', ['email' => $email])->assertStatus(429)->assertSee('Please wait');
        }
    }

    public function test_broker_throttling_still_returns_generic_public_message(): void
    {
        $user = $this->user(UserRole::Resident, true);
        Password::createToken($user);
        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);
        Notification::assertNothingSent();
    }

    public function test_reset_link_request_validates_and_normalizes_email(): void
    {
        $this->post('/forgot-password', ['email' => 'invalid'])->assertSessionHasErrors('email');
        $user = $this->user(UserRole::Resident, true);
        $this->post('/forgot-password', ['email' => ' '.strtoupper($user->email).' '])->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_valid_reset_hashes_rotates_and_consumes_token_and_only_new_password_authenticates(): void
    {
        Event::fake([PasswordReset::class]);
        $user = $this->user(UserRole::Resident, true);
        $oldRemember = $user->remember_token;
        $token = Password::createToken($user);
        $this->assertNotSame($token, DB::table('password_reset_tokens')->value('token'));
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()->assertSee('New Password')->assertSee('Confirm New Password');
        $data = ['token' => $token, 'email' => $user->email, 'password' => 'Updated123', 'password_confirmation' => 'Updated123'];
        $this->post('/reset-password', $data)->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your password has been reset successfully. You may now sign in.');
        $this->assertTrue(Hash::check('Updated123', $user->fresh()->password));
        $this->assertFalse(Hash::check('password', $user->fresh()->password));
        $this->assertNotSame($oldRemember, $user->fresh()->remember_token);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        Event::assertDispatched(PasswordReset::class);
        $this->assertGuest();
        $this->post('/reset-password', $data)->assertRedirect(route('password.request'))->assertSessionHas('reset_error');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'Updated123'])->assertRedirect('/resident/home');
    }

    public function test_invalid_and_expired_reset_links_do_not_update_password(): void
    {
        $user = $this->user(UserRole::Resident, true);
        $oldHash = $user->password;
        $expired = Password::createToken($user);
        $this->travel(61)->minutes();
        foreach (['invalid-token', $expired] as $token) {
            $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
                ->assertStatus(400)->assertSee('This password reset link is invalid or has expired.')->assertDontSee($token);
            $this->post('/reset-password', [
                'token' => $token, 'email' => $user->email, 'password' => 'Updated123', 'password_confirmation' => 'Updated123',
            ])->assertRedirect(route('password.request'))->assertSessionHas('reset_error');
            $this->assertSame($oldHash, $user->fresh()->password);
        }
    }

    public static function invalidPasswords(): array
    {
        return [['short1', 'short1'], ['onlyletters', 'onlyletters'], ['123456789', '123456789'],
            ['Updated123', 'Different123'], ['Updated123', null], [str_repeat('A1', 37), str_repeat('A1', 37)]];
    }

    #[DataProvider('invalidPasswords')]
    public function test_reset_enforces_existing_password_rules_without_flashing_sensitive_values(string $password, ?string $confirmation): void
    {
        $user = $this->user(UserRole::Resident, true);
        $oldHash = $user->password;
        $token = Password::createToken($user);
        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);
        $this->from($url)->post('/reset-password', [
            'email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $confirmation,
        ])->assertRedirect($url)->assertSessionHasErrors('password');
        foreach (['password', 'password_confirmation', 'token'] as $field) {
            $this->assertNull(session()->getOldInput($field));
        }
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertTrue(Password::tokenExists($user, $token));
    }

    public function test_new_posts_require_csrf_and_no_public_admin_registration_exists(): void
    {
        $this->get('/admin/register')->assertNotFound();
        $this->get('/staff/register')->assertNotFound();
        $this->app['env'] = 'local';
        $this->post('/forgot-password', ['email' => 'resident@example.com'])->assertStatus(419);
        $this->post('/reset-password', [])->assertStatus(419);
        $this->actingAs($this->user())->post('/email/verification-notification')->assertStatus(419);
    }

    public function test_notification_branding_retains_standard_secure_links(): void
    {
        $user = $this->user();
        $verification = (new VerifyEmail)->toMail($user);
        $this->assertSame('Verify your SmartBarangay email address', $verification->subject);
        $this->assertStringContainsString('signature=', $verification->actionUrl);
        $this->assertStringContainsString('Nasugbu, Batangas', $verification->salutation);
        $reset = (new ResetPassword('test-token'))->toMail($user);
        $this->assertSame('Reset your SmartBarangay password', $reset->subject);
        $this->assertStringContainsString('/reset-password/test-token', $reset->actionUrl);
    }

    public function test_reset_does_not_change_role_activation_or_email_verification(): void
    {
        $user = $this->user(UserRole::Admin, true, false);
        $this->post('/reset-password', [
            'token' => Password::createToken($user), 'email' => $user->email,
            'password' => 'Updated123', 'password_confirmation' => 'Updated123',
        ])->assertRedirect(route('login'));
        $this->assertSame(UserRole::Admin, $user->fresh()->role);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->post('/login', ['email' => $user->email, 'password' => 'Updated123'])
            ->assertSessionHasErrors(['email' => EnsureAccountIsActive::MESSAGE]);
    }

    public function test_reset_submissions_are_server_throttled(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/reset-password', [])->assertSessionHasErrors();
        }
        $this->post('/reset-password', [])->assertStatus(429)->assertSee('Please wait');
    }

    public function test_password_mail_failure_keeps_the_public_response_generic(): void
    {
        Password::shouldReceive('sendResetLink')->once()->andThrow(new TransportException('Simulated mail delivery failure'));
        $this->post('/forgot-password', ['email' => 'resident@example.com'])
            ->assertRedirect(route('password.request'))->assertSessionHas('status', PasswordResetController::SENT_MESSAGE)
            ->assertSessionHasNoErrors();
    }

    #[DataProvider('roles')]
    public function test_unverified_accounts_cannot_request_or_redeem_password_reset_links(UserRole $role, string $home): void
    {
        Event::fake([PasswordReset::class]);
        $user = $this->user($role);
        $oldHash = $user->password;
        $oldRemember = $user->remember_token;
        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE)
            ->assertSessionHasNoErrors();
        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->get('/forgot-password')->assertSee('Sign in with your existing password')
            ->assertSee('contact the Barangay Calayo office')->assertDontSee($user->email);

        // A previously issued or manually constructed valid broker token cannot bypass verification.
        $token = Password::createToken($user);
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertStatus(400)->assertSee('This password reset link is invalid or has expired.');
        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'Updated123', 'password_confirmation' => 'Updated123',
        ])->assertRedirect(route('password.request'))->assertSessionHas('reset_error');
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertSame($oldRemember, $user->fresh()->remember_token);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Event::assertNotDispatched(PasswordReset::class);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('verification.notice'));
        $this->post('/email/verification-notification')
            ->assertRedirect(route('verification.notice'));
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_reset_is_blocked_if_email_verification_is_removed_after_link_was_issued(): void
    {
        $user = $this->user(UserRole::Resident, true);
        $token = Password::createToken($user);
        $oldHash = $user->password;
        $user->forceFill(['email_verified_at' => null])->save();
        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'Updated123', 'password_confirmation' => 'Updated123',
        ])->assertRedirect(route('password.request'))->assertSessionHas('reset_error');
        $this->assertSame($oldHash, $user->fresh()->password);
    }

    #[DataProvider('roles')]
    public function test_guest_verification_link_resumes_after_login_and_unlocks_services(UserRole $role, string $home): void
    {
        $user = $this->user($role);
        $link = $this->signedLink($user);
        $this->get($link)->assertRedirect(route('login'))->assertSessionHas('url.intended', $link);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($link)->assertSessionMissing('url.intended');
        $this->get($link)->assertRedirect($home)->assertSessionHas('status', 'Email verified successfully.');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get($home)->assertOk()->assertSee('Email verified successfully.');
        $this->get('/email/verify')->assertRedirect($home);
    }

    public function test_login_does_not_resume_another_accounts_verification_link(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $this->get($this->signedLink($owner))->assertRedirect(route('login'));
        $this->post('/login', ['email' => $other->email, 'password' => 'password'])
            ->assertRedirect(route('verification.notice'))->assertSessionMissing('url.intended');
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_resuming_verification_after_login_still_rejects_expired_and_tampered_links(): void
    {
        $user = $this->user();
        foreach ([$this->signedLink($user, -1), $this->signedLink($user).'tampered'] as $link) {
            $this->get($link)->assertRedirect(route('login'));
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect($link);
            $this->get($link)->assertForbidden()->assertSee('This verification link is invalid or has expired.');
            $this->assertFalse($user->fresh()->hasVerifiedEmail());
            $this->post('/logout')->assertRedirect(route('login'));
        }
    }
}
