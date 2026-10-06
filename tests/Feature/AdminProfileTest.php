<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true,
            'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Storage::fake('local');
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public static function endpoints(): array
    {
        return [['GET', '/admin/profile'], ['PUT', '/admin/profile'], ['GET', '/admin/profile/picture'],
            ['POST', '/admin/profile/picture'], ['DELETE', '/admin/profile/picture'], ['GET', '/admin/users/1/picture']];
    }

    #[DataProvider('endpoints')]
    public function test_admin_endpoints_require_active_verified_admin(string $method, string $path): void
    {
        $this->call($method, $path)->assertRedirect(route('login'));
        $this->actingAs($this->user(UserRole::Resident))->call($method, $path)->assertForbidden();
        $admin = $this->user();
        $admin->email_verified_at = null;
        $admin->save();
        $this->actingAs($admin)->call($method, $path)->assertRedirect(route('verification.notice'));
        $admin->is_active = false;
        $admin->save();
        $this->actingAs($admin)->call($method, $path)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_can_view_and_update_only_own_personal_information(): void
    {
        $admin = $this->user();
        $other = $this->user();
        $original = $other->getAttributes();
        $verified = $admin->email_verified_at->toDateTimeString();
        $this->actingAs($admin)->get('/admin/profile')->assertOk()->assertViewIs('profile.edit')
            ->assertSee('Admin Area')->assertSee($admin->email)->assertSee('Default avatar');
        $this->put('/admin/profile', $this->fields())->assertRedirect(route('admin.profile.edit'))->assertSessionHasNoErrors();
        $this->assertSame('Updated Admin', $admin->fresh()->name);
        $this->assertSame('0917 123 4567', $admin->fresh()->contact_number);
        $this->assertSame('Barangay Calayo', $admin->fresh()->address);
        $this->assertSame($verified, $admin->fresh()->email_verified_at->toDateTimeString());
        $this->assertSame($original, $other->fresh()->getAttributes());
        $this->put('/admin/profile/'.$other->id, $this->fields())->assertNotFound();
        $this->get('/resident/profile')->assertForbidden();
    }

    public static function protectedFields(): array
    {
        return [['role', 'resident'], ['is_active', false], ['email_verified_at', '2026-10-06'],
            ['permissions', ['admin']], ['email', 'new@example.test'], ['password', 'Changed123'],
            ['user_id', 99], ['profile_picture', '../other.png']];
    }

    #[DataProvider('protectedFields')]
    public function test_protected_fields_are_rejected(string $field, mixed $value): void
    {
        $admin = $this->user();
        $original = $admin->getAttributes();
        $this->actingAs($admin)->put('/admin/profile', [...$this->fields(), $field => $value])->assertSessionHasErrors($field);
        $this->assertSame($original, $admin->fresh()->getAttributes());
    }

    public function test_admin_upload_replace_remove_and_invalid_upload_use_existing_security(): void
    {
        $admin = $this->user();
        $verified = $admin->email_verified_at->toDateTimeString();
        $this->actingAs($admin)->post('/admin/profile/picture', ['profile_picture' => $this->image()])->assertSessionHasNoErrors();
        $first = $admin->fresh()->profile_picture;
        Storage::disk('local')->assertExists($first);
        $this->actingAs($admin->refresh())->get('/admin/profile/picture')->assertOk()->assertHeader('content-type', 'image/jpeg');
        foreach ([UploadedFile::fake()->createWithContent('fake.jpg', '<?php echo 1;'),
            UploadedFile::fake()->createWithContent('image.php', file_get_contents(base_path('tests/Fixtures/profile/picture.jpg'))),
            UploadedFile::fake()->createWithContent('large.jpg', file_get_contents(base_path('tests/Fixtures/profile/picture.jpg')).str_repeat('x', 2097153))] as $file) {
            $this->post('/admin/profile/picture', ['profile_picture' => $file])->assertSessionHasErrors('profile_picture');
            $this->assertSame($first, $admin->fresh()->profile_picture);
        }
        $this->post('/admin/profile/picture', ['profile_picture' => $this->image()])->assertSessionHasNoErrors();
        $second = $admin->fresh()->profile_picture;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        $this->delete('/admin/profile/picture')->assertRedirect(route('admin.profile.edit'));
        Storage::disk('local')->assertMissing($second);
        $this->assertNull($admin->fresh()->profile_picture);
        $this->assertSame($verified, $admin->fresh()->email_verified_at->toDateTimeString());
    }

    public function test_user_management_shows_resident_pictures_and_defaults_without_credentials_or_edit_controls(): void
    {
        $resident = $this->user(UserRole::Resident);
        $default = $this->user(UserRole::Resident);
        $path = 'profile-pictures/'.$resident->id.'/fixture.jpg';
        Storage::disk('local')->put($path, file_get_contents(base_path('tests/Fixtures/profile/picture.jpg')));
        $resident->profile_picture = $path;
        $resident->save();
        $this->actingAs($this->user());
        $this->get('/admin/users')->assertOk()->assertSee(route('admin.users.picture', $resident))
            ->assertSee('Default avatar for '.$default->name)->assertDontSee($resident->password);
        $this->get('/admin/users/'.$resident->id)->assertOk()->assertSee(route('admin.users.picture', $resident))
            ->assertDontSee($resident->password)->assertDontSee('name="profile_picture"', false);
        $this->get('/admin/users/'.$resident->id.'/picture')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/admin/users/'.$default->id.'/picture')->assertNotFound();
        $this->post('/admin/users/'.$resident->id.'/picture', ['profile_picture' => $this->image()])->assertStatus(405);
        $this->delete('/admin/users/'.$resident->id.'/picture')->assertStatus(405);
        $this->assertSame($path, $resident->fresh()->profile_picture);
        $resident->profile_picture = 'profile-pictures/'.$default->id.'/foreign.jpg';
        $resident->save();
        $this->get('/admin/users/'.$resident->id.'/picture')->assertNotFound();
        $this->get('/admin/users/'.$resident->id)->assertSee('Default avatar for '.$resident->name);
        $this->get('/admin/users/'.$this->user()->id.'/picture')->assertNotFound();
    }

    public function test_csrf_is_preserved_for_admin_profile_mutations(): void
    {
        $this->actingAs($this->user());
        $this->app['env'] = 'local';
        $this->put('/admin/profile', $this->fields())->assertStatus(419);
        $this->post('/admin/profile/picture', ['profile_picture' => $this->image()])->assertStatus(419);
        $this->delete('/admin/profile/picture')->assertStatus(419);
    }

    private function fields(): array
    {
        return ['name' => 'Updated Admin', 'contact_number' => '0917 123 4567', 'address' => 'Barangay Calayo'];
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('original.jpg', file_get_contents(base_path('tests/Fixtures/profile/picture.jpg')));
    }

    private function user(UserRole $role = UserRole::Admin): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->refresh();
    }
}
