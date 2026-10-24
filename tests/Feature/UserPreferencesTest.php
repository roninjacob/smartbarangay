<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserPreferencesTest extends TestCase
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

    public static function roles(): array
    {
        return [[UserRole::Admin], [UserRole::Resident]];
    }

    private function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, ...$attributes]);
    }

    #[DataProvider('roles')]
    public function test_guest_cannot_access_or_change_preferences(UserRole $role): void
    {
        $this->get(route($role->value.'.settings.edit'))->assertRedirect(route('login'));
        $this->put(route($role->value.'.settings.update'), ['theme_preference' => 'dark'])->assertRedirect(route('login'));
    }

    #[DataProvider('roles')]
    public function test_authorized_roles_have_settings_and_about_with_safe_admin_placeholder(UserRole $role): void
    {
        $user = $this->user($role);
        $response = $this->actingAs($user)->get(route($role->value.'.settings.edit'));
        $response->assertOk()->assertSee('Appearance')->assertSee('Dark mode')->assertSee('About SmartBarangay')
            ->assertSee('Save appearance')->assertSee('data-bs-theme="light"', false)
            ->assertSee(route($role->value.'.settings.edit'), false)->assertDontSee('Public Website Settings');
        if ($role === UserRole::Admin) {
            $response->assertSee('System controls')->assertSee('No operational switches are available');
        } else {
            $response->assertDontSee('System controls')->assertDontSee('ADMIN ONLY');
        }
        $this->assertSame('light', $user->fresh()->theme_preference);
        $this->assertFalse(Schema::hasTable('public_site_settings'));
    }

    #[DataProvider('roles')]
    public function test_wrong_role_is_forbidden_for_read_and_write(UserRole $role): void
    {
        $otherRole = $role === UserRole::Admin ? UserRole::Resident : UserRole::Admin;
        $this->actingAs($this->user($otherRole));
        $this->get(route($role->value.'.settings.edit'))->assertForbidden();
        $this->put(route($role->value.'.settings.update'), ['theme_preference' => 'dark'])->assertForbidden();
    }

    #[DataProvider('roles')]
    public function test_unverified_accounts_cannot_read_or_write_settings(UserRole $role): void
    {
        $user = $this->user($role, ['email_verified_at' => null]);
        $this->actingAs($user)->get(route($role->value.'.settings.edit'))->assertRedirect(route('verification.notice'));
        $this->put(route($role->value.'.settings.update'), ['theme_preference' => 'dark'])->assertRedirect(route('verification.notice'));
        $this->assertSame('light', $user->fresh()->theme_preference);
    }

    #[DataProvider('roles')]
    public function test_inactive_accounts_cannot_read_or_write_settings(UserRole $role): void
    {
        $user = $this->user($role, ['is_active' => false]);
        $this->actingAs($user)->get(route($role->value.'.settings.edit'))->assertRedirect(route('login'));
        $this->actingAs($user)->put(route($role->value.'.settings.update'), ['theme_preference' => 'dark'])->assertRedirect(route('login'));
        $this->assertSame('light', $user->fresh()->theme_preference);
    }

    #[DataProvider('roles')]
    public function test_theme_saves_only_for_current_account_and_survives_a_new_session(UserRole $role): void
    {
        $user = $this->user($role);
        $other = $this->user($role);
        $before = $user->fresh()->getAttributes();
        $this->actingAs($user)->put(route($role->value.'.settings.update'), ['theme_preference' => 'dark'])
            ->assertRedirect(route($role->value.'.settings.edit'))->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->assertSame('dark', $user->fresh()->theme_preference);
        $this->assertSame('light', $other->fresh()->theme_preference);
        foreach ($before as $field => $value) {
            if (! in_array($field, ['theme_preference', 'updated_at'])) {
                $this->assertSame($value, $user->fresh()->getAttributes()[$field], $field.' must not change');
            }
        }
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->flushSession();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route($user->homeRouteName()));
        $this->get(route($role->value.'.settings.edit'))->assertOk()->assertSee('data-bs-theme="dark"', false);
        $this->get('/')->assertOk()->assertSee('data-bs-theme="dark"', false)->assertSee('Go to Dashboard');
        $this->put(route($role->value.'.settings.update'), ['theme_preference' => 'light'])->assertSessionHasNoErrors();
        $this->get(route($role->value.'.settings.edit'))->assertSee('data-bs-theme="light"', false);
    }

    public static function invalidThemes(): array
    {
        return [[null], [''], ['system'], ['DARK'], ['<script>alert(1)</script>'], [['dark']]];
    }

    #[DataProvider('invalidThemes')]
    public function test_only_explicit_supported_themes_are_accepted(mixed $theme): void
    {
        $user = $this->user(UserRole::Resident);
        $this->actingAs($user)->put(route('resident.settings.update'), ['theme_preference' => $theme])
            ->assertSessionHasErrors('theme_preference');
        $this->assertSame('light', $user->fresh()->theme_preference);
    }

    #[DataProvider('roles')]
    public function test_identity_and_protected_fields_cannot_be_changed(UserRole $role): void
    {
        $user = $this->user($role);
        $other = $this->user($role);
        $this->actingAs($user)->put(route($role->value.'.settings.update'), [
            'theme_preference' => 'dark', 'user_id' => $other->id, 'id' => $other->id, 'role' => 'admin',
            'is_active' => false, 'email_verified_at' => now()->toIso8601String(), 'password' => 'changed', 'maintenance_mode' => true,
        ])->assertSessionHasErrors('preferences');
        $this->assertSame('light', $user->fresh()->theme_preference);
        $this->assertSame('light', $other->fresh()->theme_preference);
        $this->assertTrue($user->fresh()->is_active);
    }

    #[DataProvider('roles')]
    public function test_preferences_require_csrf(UserRole $role): void
    {
        $user = $this->user($role);
        $this->app->instance('env', 'local');
        try {
            $this->actingAs($user)->put(route($role->value.'.settings.update'), ['theme_preference' => 'dark'])->assertStatus(419);
            $this->assertSame('light', $user->fresh()->theme_preference);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_guest_landing_is_preserved_without_public_settings_storage(): void
    {
        $this->get('/')->assertOk()->assertSee('data-bs-theme="light"', false)->assertSee('Request a Document')
            ->assertSee('Sign In')->assertSee('Register')->assertSee('Services will appear here when available.')
            ->assertSee('Office hours have not been posted yet.')->assertDontSee('Go to Dashboard');
        $this->assertFalse(Schema::hasTable('public_site_settings'));
    }

    #[DataProvider('roles')]
    public function test_authenticated_landing_has_role_correct_actions(UserRole $role): void
    {
        $user = $this->user($role);
        $this->actingAs($user)->get('/')->assertOk()->assertSee('Go to Dashboard')
            ->assertSee($role === UserRole::Resident ? 'View My Requests' : 'View Reservations')
            ->assertSee(route($user->homeRouteName()), false)->assertDontSee('Create an Account');
    }

    public function test_landing_only_projects_paginated_active_service_names_and_descriptions(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            Service::create(['name' => sprintf('Public %02d', $i), 'description' => '<script>alert(1)</script>', 'is_active' => true, 'fee_amount' => '9876.54']);
        }
        Service::create(['name' => 'Hidden inactive service', 'is_active' => false]);
        $response = $this->get('/');
        $response->assertOk()->assertSee('Public 01')->assertSee('Public 12')->assertDontSee('Public 13')
            ->assertDontSee('Hidden inactive service')->assertDontSee('9876.54')->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
        $this->assertCount(12, $response->viewData('services'));
        $this->assertSame(['id', 'name', 'description'], array_keys($response->viewData('services')->first()->getAttributes()));
        $this->get('/?services_page=2')->assertOk()->assertSee('Public 13')->assertSee('Public 14')->assertDontSee('Public 01');
    }

    public function test_viewing_settings_and_public_page_does_not_write_preferences(): void
    {
        $user = $this->user(UserRole::Resident);
        $before = $user->fresh()->getAttributes();
        $this->actingAs($user)->get(route('resident.settings.edit'))->assertOk();
        $this->get('/')->assertOk();
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_unsupported_stored_theme_falls_back_safely(): void
    {
        $user = $this->user(UserRole::Resident, ['theme_preference' => 'other']);
        $this->actingAs($user)->get('/')->assertOk()->assertSee('data-bs-theme="light"', false);
    }
}
