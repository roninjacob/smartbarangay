<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Auth\Notifications\VerifyEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Follow Phase 1's isolated database approach, even if local .env uses MySQL.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.foreign_key_constraints' => true,
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_guest_root_redirects_to_login_and_auth_pages_show_calayo_branding(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        foreach (['/login', '/register'] as $path) {
            $this->get($path)->assertOk()->assertSee('SmartBarangay')
                ->assertSee('Barangay Calayo')->assertSee('Nasugbu, Batangas')
                ->assertSee('name="_token"', false)
                ->assertDontSee('name="role"', false)
                ->assertDontSee('name="is_active"', false);
        }
    }

    public function test_valid_resident_registration_is_hashed_and_enters_verification(): void
    {
        $this->post('/register', $this->registrationData())->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status');
        $user = User::sole();
        $this->assertSame(UserRole::Resident, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertSame('0917 123 4567', $user->contact_number);
        $this->assertSame('Sitio Test, Barangay Calayo', $user->address);
        $this->assertTrue(Hash::check('Resident123', $user->password));
        $this->assertNotSame('Resident123', $user->password);
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    #[DataProvider('injectedRoles')]
    public function test_public_registration_ignores_privileged_fields(string $role): void
    {
        $this->post('/register', [
            ...$this->registrationData(), 'role' => $role, 'is_active' => false,
            'email_verified_at' => now()->toDateTimeString(),
        ])->assertRedirect(route('verification.notice'));

        $user = User::sole();
        $this->assertSame(UserRole::Resident, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->email_verified_at);
    }

    public static function injectedRoles(): array
    {
        return [['admin'], ['staff']];
    }

    public function test_registration_rejects_duplicate_email_case_insensitively(): void
    {
        User::factory()->create(['email' => 'resident@example.com']);
        $this->from('/register')->post('/register', [
            ...$this->registrationData(), 'email' => 'RESIDENT@example.com',
        ])->assertRedirect('/register')->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
    }

    #[DataProvider('invalidRegistration')]
    public function test_registration_validates_inputs(string $field, mixed $value): void
    {
        $this->from('/register')->post('/register', [
            ...$this->registrationData(), $field => $value,
        ])->assertRedirect('/register')->assertSessionHasErrors($field === 'password_confirmation' ? 'password' : $field);
        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public static function invalidRegistration(): array
    {
        return [
            ['email', 'not-an-email'], ['name', ''], ['email', ''],
            ['contact_number', ''], ['address', ''], ['password', ''],
            ['password_confirmation', 'different'],
            ['password', 'short1'], ['password', 'onlyletters'],
            ['password', '123456789'], ['contact_number', 'not-a-number'],
            ['name', str_repeat('x', 256)], ['address', str_repeat('x', 1001)],
            ['password', str_repeat('A1', 37)],
        ];
    }

    public function test_all_required_fields_are_validated_and_passwords_are_not_flashed(): void
    {
        $this->from('/register')->post('/register', [])
            ->assertSessionHasErrors(['name', 'email', 'contact_number', 'address', 'password']);

        $this->from('/register')->post('/register', [
            ...$this->registrationData(), 'email' => 'invalid',
        ])->assertSessionHasErrors('email')
            ->assertSessionHasInput('name', 'Calayo Resident')
            ->assertSessionHasInput('address', 'Sitio Test, Barangay Calayo');
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
        $this->get('/register')->assertDontSee('value="Resident123"', false);
    }

    #[DataProvider('contactNumbers')]
    public function test_registration_accepts_common_mobile_and_landline_formats(string $number): void
    {
        $this->post('/register', [
            ...$this->registrationData(), 'contact_number' => $number,
        ])->assertSessionHasNoErrors()->assertRedirect(route('verification.notice'));
    }

    public static function contactNumbers(): array
    {
        return [['+63 917 123 4567'], ['0917-123-4567'], ['(043) 123-4567'], ['(02) 8123-4567'], ['8123-4567']];
    }

    #[DataProvider('roles')]
    public function test_successful_login_regenerates_session_and_redirects_by_role(UserRole $role, string $home): void
    {
        $user = $this->createUser($role);
        $this->withSession(['marker' => 'keep', 'url.intended' => '/wrong-area']);
        $sessionId = session()->getId();

        $this->post('/login', ['email' => strtoupper($user->email), 'password' => 'password'])
            ->assertRedirect($home)->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNull(session('url.intended'));
        $this->get($home)->assertOk()->assertSee($user->name)
            ->assertSee($role === UserRole::Admin ? 'Admin Area' : 'Resident Area')
            ->assertSee('Logout')->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function roles(): array
    {
        return [[UserRole::Resident, '/resident/home'], [UserRole::Admin, '/admin/home']];
    }

    public function test_invalid_credentials_use_identical_generic_errors(): void
    {
        $user = $this->createUser(UserRole::Resident);
        foreach ([
            ['email' => $user->email, 'password' => 'wrong-password'],
            ['email' => 'unknown@example.com', 'password' => 'wrong-password'],
        ] as $credentials) {
            $this->from('/login')->post('/login', $credentials)
                ->assertRedirect('/login')->assertSessionHasErrors([
                    'email' => 'The provided credentials do not match our records.',
                ]);
            $this->assertGuest();
            $this->assertNull(session()->getOldInput('password'));
        }
    }

    public function test_login_validates_required_fields_and_email(): void
    {
        $this->post('/login', [])->assertSessionHasErrors(['email', 'password']);
        $this->post('/login', ['email' => 'invalid', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_rate_limiter_blocks_repeated_failed_attempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])
                ->assertSessionHasErrors(['email' => 'The provided credentials do not match our records.']);
        }
        $this->post('/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many sign-in attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    #[DataProvider('roles')]
    public function test_inactive_accounts_cannot_log_in(UserRole $role, string $home): void
    {
        $user = $this->createUser($role, false);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => EnsureAccountIsActive::MESSAGE]);
        $this->assertGuest();
        $this->get($home)->assertRedirect(route('login'));
    }

    #[DataProvider('roles')]
    public function test_deactivation_ends_an_existing_session(UserRole $role, string $home): void
    {
        $user = $this->createUser($role);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($home);
        $this->withSession(['private_marker' => 'remove']);
        $token = session()->token();
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        Auth::forgetGuards();

        $this->get($home)->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => EnsureAccountIsActive::MESSAGE]);
        $this->assertGuest();
        $this->assertNull(session('private_marker'));
        $this->assertNotSame($token, session()->token());
    }

    public function test_inactive_session_on_guest_routes_or_root_does_not_redirect_to_home(): void
    {
        $user = $this->createUser(UserRole::Resident, false);
        foreach (['/', '/login', '/register'] as $path) {
            $this->actingAs($user)->get($path)->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_guests_cannot_access_either_role_home(): void
    {
        $this->get('/resident/home')->assertRedirect(route('login'));
        $this->get('/admin/home')->assertRedirect(route('login'));
    }

    #[DataProvider('roles')]
    public function test_wrong_role_access_is_forbidden(UserRole $role, string $home): void
    {
        $user = $this->createUser($role);
        $wrongHome = $role === UserRole::Resident ? '/admin/home' : '/resident/home';
        $this->actingAs($user)->get($wrongHome)->assertForbidden()
            ->assertSee('This area is restricted.')->assertDontSee('Welcome,');
    }

    #[DataProvider('roles')]
    public function test_authenticated_users_are_redirected_from_root_and_guest_routes(UserRole $role, string $home): void
    {
        $this->actingAs($this->createUser($role));
        foreach (['/', '/login', '/register'] as $path) {
            $this->get($path)->assertRedirect($home);
        }
        foreach (['/login', '/register'] as $path) {
            $this->post($path, $this->registrationData())->assertRedirect($home);
        }
        $this->assertSame(1, User::count());
    }

    #[DataProvider('roles')]
    public function test_logout_invalidates_session_and_protected_access(UserRole $role, string $home): void
    {
        $user = $this->createUser($role);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->withSession(['private_marker' => 'remove']);
        $sessionId = session()->getId();
        $token = session()->token();

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->assertNull(session('private_marker'));
        $this->get('/resident/home')->assertRedirect(route('login'));
        $this->get('/admin/home')->assertRedirect(route('login'));
        $this->get('/logout')->assertStatus(405);
    }

    public function test_authentication_posts_require_csrf_tokens(): void
    {
        // Laravel bypasses CSRF under "testing"; enable it for this isolated test.
        $this->app['env'] = 'local';
        $this->post('/register', $this->registrationData())->assertStatus(419);
        $this->post('/login', ['email' => 'resident@example.com', 'password' => 'password'])->assertStatus(419);
        $this->actingAs($this->createUser(UserRole::Resident))->post('/logout')->assertStatus(419);
        $this->assertSame(1, User::count());
    }

    public function test_admin_command_creates_only_a_hashed_active_admin(): void
    {
        $this->artisan('smartbarangay:create-admin')
            ->expectsQuestion('Admin Full Name', 'Local Test Admin')
            ->expectsQuestion('Admin Email', 'ADMIN@example.com')
            ->expectsQuestion('Contact Number (optional)', null)
            ->expectsQuestion('Password', 'AdminTest123')
            ->expectsQuestion('Password Confirmation', 'AdminTest123')
            ->expectsOutput('SmartBarangay administrator created successfully.')
            ->assertExitCode(0);
        $admin = User::sole();
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertSame('admin@example.com', $admin->email);
        $this->assertTrue(Hash::check('AdminTest123', $admin->password));
        $this->assertNotSame('AdminTest123', $admin->password);
        $this->assertFalse($admin->hasVerifiedEmail());
        Notification::assertSentTo($admin, VerifyEmail::class);
    }

    public function test_admin_command_rejects_duplicate_email_without_overwriting(): void
    {
        $resident = User::factory()->create(['email' => 'existing@example.com']);
        $password = $resident->password;
        $this->artisan('smartbarangay:create-admin')
            ->expectsQuestion('Admin Full Name', 'Test Admin')
            ->expectsQuestion('Admin Email', 'EXISTING@example.com')
            ->expectsQuestion('Contact Number (optional)', '0917 123 4567')
            ->expectsQuestion('Password', 'AdminTest123')
            ->expectsQuestion('Password Confirmation', 'AdminTest123')
            ->expectsOutput('The email has already been taken.')
            ->assertExitCode(1);
        $this->assertSame(1, User::count());
        $this->assertSame(UserRole::Resident, $resident->fresh()->role);
        $this->assertSame($password, $resident->fresh()->password);
    }

    public function test_admin_command_rejects_invalid_data_and_mismatched_passwords(): void
    {
        $this->artisan('smartbarangay:create-admin')
            ->expectsQuestion('Admin Full Name', '')
            ->expectsQuestion('Admin Email', 'invalid')
            ->expectsQuestion('Contact Number (optional)', 'invalid')
            ->expectsQuestion('Password', 'AdminTest123')
            ->expectsQuestion('Password Confirmation', 'Different123')
            ->expectsOutput('The name field is required.')
            ->expectsOutput('The email field must be a valid email address.')
            ->expectsOutput('Enter a valid Philippine mobile or landline number.')
            ->expectsOutput('The password field confirmation does not match.')
            ->assertExitCode(1);
        $this->assertSame(0, User::count());
    }

    public function test_admin_command_rejects_noninteractive_execution(): void
    {
        $this->artisan('smartbarangay:create-admin', ['--no-interaction' => true])
            ->expectsOutput('Run this command interactively to enter the administrator details securely.')
            ->assertExitCode(1);
        $this->assertSame(0, User::count());
    }

    private function registrationData(): array
    {
        return [
            'name' => 'Calayo Resident',
            'email' => 'resident@example.com',
            'contact_number' => '0917 123 4567',
            'address' => 'Sitio Test, Barangay Calayo',
            'password' => 'Resident123',
            'password_confirmation' => 'Resident123',
        ];
    }

    private function createUser(UserRole $role, bool $active = true): User
    {
        $user = User::factory()->make();
        $user->role = $role;
        $user->is_active = $active;
        $user->save();

        return $user->refresh();
    }
}
