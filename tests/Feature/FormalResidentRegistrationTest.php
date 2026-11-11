<?php

namespace Tests\Feature;

use App\Enums\ResidencyStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ResidentProfile;
use App\Models\User;
use App\Services\ReservationDocumentInformation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FormalResidentRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'cache.default' => 'array', 'session.driver' => 'array']);
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

    private function identity(array $changes = []): array
    {
        return array_replace(['first_name' => 'María Ana', 'middle_name' => 'De la Cruz', 'last_name' => "O'Connor-Santos",
            'suffix' => 'Jr.', 'birthdate' => '2000-02-29', 'contact_number' => '09171234567',
            'house_unit_number' => '12-B', 'address_street' => 'Coastal Road', 'address_zone_purok' => 'Purok 1'], $changes);
    }

    private function register(array $changes = [])
    {
        return $this->post(route('register.store'), array_replace($this->identity(), [
            'email' => 'synthetic@example.test', 'password' => 'Resident123!', 'password_confirmation' => 'Resident123!',
        ], $changes));
    }

    public function test_registration_stores_one_canonical_profile_with_server_formatted_projections(): void
    {
        $this->register(['name' => 'Injected Admin', 'address' => 'Different barangay', 'barangay' => 'Other',
            'role' => 'admin', 'residency_status' => 'approved'])->assertSessionHasNoErrors()->assertRedirect(route('verification.notice'));
        $user = User::sole();
        $this->assertSame("María Ana De la Cruz O'Connor-Santos Jr.", $user->name);
        $this->assertSame($user->name, $user->full_name);
        $this->assertSame('12-B, Coastal Road, Purok 1, Barangay Calayo, Nasugbu, Batangas', $user->address);
        $this->assertSame(UserRole::Resident, $user->role);
        $this->assertSame(ResidencyStatus::Pending, $user->residency_status);
        $this->assertTrue(Hash::check('Resident123!', $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertSame('2000-02-29', $user->profile->birthdate->toDateString());
        $this->assertNull($user->profile->civil_status);
        $this->assertDatabaseCount('resident_profiles', 1);
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(20), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($link)->assertRedirect(route('resident.home'));
        $this->get(route('resident.home'))->assertOk();
        $this->get(route('resident.reservations.create'))->assertForbidden();
        $this->get(route('resident.profile.edit'))->assertOk()->assertSee('Coastal Road')
            ->assertSee('name="first_name"', false)->assertDontSee('name="name"', false);
        $data = app(ReservationDocumentInformation::class)->profile($user->fresh());
        $this->assertSame($user->name, $data['resident_name']);
        $this->assertSame($user->address, $data['resident_address']);
    }

    public static function invalidIdentity(): array
    {
        return [['first_name', ''], ['last_name', null], ['first_name', '<script>'], ['middle_name', ['bad']],
            ['last_name', str_repeat('x', 81)], ['suffix', str_repeat('x', 21)], ['birthdate', '2999-01-01'],
            ['birthdate', '2001-02-29'], ['birthdate', null], ['contact_number', 'invalid'],
            ['address_street', ''], ['house_unit_number', str_repeat('x', 81)], ['address_zone_purok', str_repeat('x', 121)]];
    }

    #[DataProvider('invalidIdentity')]
    public function test_invalid_registration_creates_no_partial_account_or_profile(string $field, mixed $value): void
    {
        $this->register([$field => $value])->assertSessionHasErrors($field);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('resident_profiles', 0);
        $this->assertGuest();
    }

    public function test_optional_names_and_house_are_normalized_and_combined_name_is_bounded(): void
    {
        $this->register(['first_name' => '  Ana   María  ', 'middle_name' => '  ', 'suffix' => null,
            'house_unit_number' => null, 'address_zone_purok' => null])->assertSessionHasNoErrors();
        $user = User::sole();
        $this->assertSame("Ana María O'Connor-Santos", $user->full_name);
        $this->assertNull($user->middle_name);
        $this->assertSame('Coastal Road, Barangay Calayo, Nasugbu, Batangas', $user->address);
        $this->post(route('logout'));
        $this->register(['email' => 'another@example.test', 'first_name' => str_repeat('A', 80),
            'middle_name' => str_repeat('B', 80), 'last_name' => str_repeat('C', 80), 'suffix' => str_repeat('I', 20)])
            ->assertSessionHasErrors('first_name');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_legacy_user_is_not_split_until_manual_completion_and_requires_review(): void
    {
        $user = User::factory()->residencyApproved()->create(['name' => 'Dela Cruz, Ana M.',
            'address' => 'Original legacy address', 'role' => UserRole::Resident, 'is_active' => true]);
        $before = $user->refresh()->getRawOriginal();
        $this->assertSame('Dela Cruz, Ana M.', $user->full_name);
        $this->actingAs($user)->get(route('resident.profile.edit'))->assertOk()->assertSee('Dela Cruz, Ana M.')
            ->assertSee('Complete your personal information');
        $this->assertNull($user->fresh()->first_name);
        $this->put(route('resident.profile.update'), $this->identity())->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame(ResidencyStatus::Pending, $user->residency_status);
        foreach (['password', 'email', 'email_verified_at', 'role', 'is_active'] as $field) {
            $this->assertSame($before[$field], $user->getRawOriginal($field));
        }
        $audit = AuditLog::sole();
        $this->assertSame('Dela Cruz, Ana M.', $audit->old_values['fields']['name']);
        $this->assertSame('Original legacy address', $audit->old_values['fields']['address']);
        $this->assertSame($user->name, $audit->new_values['fields']['name']);
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->actingAs($admin)->get(route('admin.users.show', $user))->assertOk()->assertSee('First name')->assertSee('12-B');
        $this->patch(route('admin.users.residency', $user), ['residency_status' => 'approved',
            'expected_residency_revision' => $user->residency_revision])->assertSessionHasNoErrors();
        $this->actingAs($user->fresh())->get(route('resident.reservations.create'))->assertOk();
    }

    public function test_structured_profile_updates_keep_projections_and_review_in_sync(): void
    {
        $this->register()->assertSessionHasNoErrors();
        $user = User::sole();
        $user->forceFill(['email_verified_at' => now(), 'residency_status' => 'approved'])->save();
        $this->actingAs($user)->put(route('resident.profile.update'), $this->identity(['contact_number' => '09181234567']))->assertSessionHasNoErrors();
        $this->assertSame(ResidencyStatus::Approved, $user->fresh()->residency_status);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->put(route('resident.profile.update'), $this->identity(['house_unit_number' => '14']))->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame(ResidencyStatus::Pending, $user->residency_status);
        $this->assertStringStartsWith('14, ', $user->address);
        $this->assertSame('14', AuditLog::sole()->new_values['fields']['house_unit_number']);
        $this->put(route('resident.profile.update'), ['name' => 'Bypass', 'address' => 'Bypass'])->assertSessionHasErrors('first_name');
        $this->put(route('resident.profile.demographics.update'), ['birthdate' => null, 'address_street' => null,
            'civil_status' => null, 'occupation' => null, 'emergency_contact_name' => null, 'emergency_contact_number' => null])
            ->assertSessionHasErrors(['birthdate', 'address_street']);
        $this->assertSame('2000-02-29', $user->profile()->first()->birthdate->toDateString());
    }

    public function test_migration_preserves_legacy_data_and_can_be_reversed_in_isolation(): void
    {
        $migration = require database_path('migrations/2026_11_11_000035_add_formal_resident_information.php');
        $migration->down();
        $id = DB::table('users')->insertGetId(['name' => 'Legacy Unsplit Name', 'email' => 'legacy@example.test',
            'password' => 'existing-hash', 'role' => 'resident', 'residency_status' => 'approved', 'address' => 'Original address']);
        $migration->up();
        $row = DB::table('users')->find($id);
        $this->assertSame('Legacy Unsplit Name', $row->name);
        $this->assertNull($row->first_name);
        $this->assertSame('approved', $row->residency_status);
        $this->assertSame('existing-hash', $row->password);
        $migration->down();
        $this->assertSame('Original address', DB::table('users')->find($id)->address);
    }

    public function test_profile_insert_failure_rolls_back_new_account_before_login(): void
    {
        ResidentProfile::creating(function () {
            throw new \RuntimeException('Synthetic profile failure');
        });
        try {
            $this->register()->assertStatus(500);
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('resident_profiles', 0);
            $this->assertGuest();
        } finally {
            ResidentProfile::flushEventListeners();
            ResidentProfile::clearBootedModels();
        }
    }
}
