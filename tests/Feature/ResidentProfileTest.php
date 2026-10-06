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

class ResidentProfileTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';

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
        return [['GET', '/resident/profile'], ['PUT', '/resident/profile'],
            ['GET', '/resident/profile/picture'], ['POST', '/resident/profile/picture'],
            ['DELETE', '/resident/profile/picture']];
    }

    #[DataProvider('endpoints')]
    public function test_profile_routes_require_active_verified_resident(string $method, string $path): void
    {
        $this->call($method, $path)->assertRedirect(route('login'));
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->call($method, $path)->assertForbidden();
        $admin->email_verified_at = null;
        $admin->save();
        $this->actingAs($admin)->call($method, $path)->assertForbidden();
        $resident = $this->user();
        $resident->email_verified_at = null;
        $resident->save();
        $this->actingAs($resident)->call($method, $path)->assertRedirect(route('verification.notice'));
        $resident->is_active = false;
        $resident->save();
        $this->actingAs($resident)->call($method, $path)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_profile_shows_own_information_and_default_avatar_and_saves_allowed_fields(): void
    {
        $user = $this->user();
        $original = $user->getAttributes();
        $this->actingAs($user)->get('/resident/profile')->assertOk()->assertSee($user->name)
            ->assertSee($user->email)->assertSee('Default avatar')->assertSee('Upload picture')
            ->assertDontSee('name="role"', false)->assertDontSee('name="email"', false);
        $this->put('/resident/profile', $this->fields())->assertRedirect(route('resident.profile.edit'))->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame('Updated Resident', $user->name);
        $this->assertSame('0917 123 4567', $user->contact_number);
        $this->assertSame('Barangay Calayo, Nasugbu, Batangas', $user->address);
        foreach (['role', 'is_active', 'email', 'email_verified_at', 'password', 'remember_token'] as $field) {
            $this->assertSame($original[$field], $user->getAttributes()[$field]);
        }
        $this->get('/resident/profile')->assertSee('Updated Resident')->assertSee($user->address);
    }

    public static function forbiddenFields(): array
    {
        return [['role', 'admin'], ['is_active', false], ['email_verified_at', '2026-10-06'],
            ['email', 'new@example.test'], ['password', 'NewPassword123'], ['profile_picture', '../other.png'],
            ['permissions', ['admin']], ['remember_token', 'forged'], ['user_id', 99], ['id', 99]];
    }

    #[DataProvider('forbiddenFields')]
    public function test_privileged_fields_and_ownership_input_are_rejected(string $field, mixed $value): void
    {
        $user = $this->user();
        $original = $user->getAttributes();
        $this->actingAs($user)->put('/resident/profile', [...$this->fields(), $field => $value])->assertSessionHasErrors($field);
        $this->assertSame($original, $user->fresh()->getAttributes());
    }

    public function test_another_account_cannot_be_addressed_or_changed(): void
    {
        $other = $this->user();
        $original = $other->getAttributes();
        $this->actingAs($this->user())->put('/resident/profile/'.$other->id, $this->fields())->assertNotFound();
        $this->put('/resident/profile', [...$this->fields(), 'user_id' => $other->id])->assertSessionHasErrors('user_id');
        $this->assertSame($original, $other->fresh()->getAttributes());
    }

    public function test_personal_information_validation_and_optional_fields(): void
    {
        $user = $this->user();
        $this->actingAs($user)->put('/resident/profile', ['name' => '', 'contact_number' => 'not a phone', 'address' => str_repeat('a', 1001)])
            ->assertSessionHasErrors(['name', 'contact_number', 'address']);
        $this->put('/resident/profile', ['name' => str_repeat('a', 256)])->assertSessionHasErrors('name');
        $this->put('/resident/profile', ['name' => 'Resident', 'contact_number' => '', 'address' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->contact_number);
        $this->assertNull($user->fresh()->address);
    }

    public function test_picture_upload_replace_remove_preserves_verification_and_cleans_up_owned_files(): void
    {
        $user = $this->user();
        $verified = $user->email_verified_at->toDateTimeString();
        $this->actingAs($user)->post('/resident/profile/picture', ['profile_picture' => $this->image()])->assertSessionHasNoErrors();
        $first = $user->fresh()->profile_picture;
        $this->assertMatchesRegularExpression('#^profile-pictures/'.$user->id.'/[a-zA-Z0-9]+\.png$#', $first);
        $this->assertStringNotContainsString('original', $first);
        Storage::disk('local')->assertExists($first);
        $this->actingAs($user->refresh());
        $this->get('/resident/profile/picture')->assertOk()->assertHeader('content-type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/resident/profile')->assertSee('Your current profile picture')->assertSee('Replace picture');
        $this->post('/resident/profile/picture', ['profile_picture' => $this->image()])->assertSessionHasNoErrors();
        $second = $user->fresh()->profile_picture;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        $this->delete('/resident/profile/picture')->assertRedirect(route('resident.profile.edit'));
        Storage::disk('local')->assertMissing($second);
        $this->assertNull($user->fresh()->profile_picture);
        $this->assertSame($verified, $user->fresh()->email_verified_at->toDateTimeString());
        $this->actingAs($user->refresh());
        $this->get('/resident/profile/picture')->assertNotFound();
        $this->get('/resident/profile')->assertSee('Default avatar');
    }

    public function test_invalid_missing_fake_extension_and_oversized_images_do_not_replace_existing_file(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/resident/profile/picture', ['profile_picture' => $this->image()])->assertSessionHasNoErrors();
        $path = $user->fresh()->profile_picture;
        foreach ([null, UploadedFile::fake()->createWithContent('fake.png', '<?php echo 1;'),
            $this->image('image.php'), $this->image('image.svg'), $this->image('fake.jpg'),
            UploadedFile::fake()->createWithContent('large.png', base64_decode(self::PNG).str_repeat('x', 2097153))] as $file) {
            $this->post('/resident/profile/picture', ['profile_picture' => $file])->assertSessionHasErrors('profile_picture');
            $this->assertSame($path, $user->fresh()->profile_picture);
            Storage::disk('local')->assertExists($path);
        }
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_picture_serving_and_cleanup_never_touch_another_users_file(): void
    {
        $other = $this->user();
        $path = 'profile-pictures/'.$other->id.'/other.png';
        Storage::disk('local')->put($path, base64_decode(self::PNG));
        $other->profile_picture = $path;
        $other->save();
        $user = $this->user();
        $user->profile_picture = $path;
        $user->save();
        $this->actingAs($user)->get('/resident/profile/picture')->assertNotFound();
        $this->post('/resident/profile/picture', ['profile_picture' => $this->image(), 'user_id' => $other->id])->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists($path);
        $this->delete('/resident/profile/picture', ['user_id' => $other->id, 'profile_picture' => $path])->assertRedirect();
        Storage::disk('local')->assertExists($path);
        $this->assertSame($path, $other->fresh()->profile_picture);
    }

    public function test_profile_mutations_require_csrf(): void
    {
        $this->actingAs($this->user());
        $this->app['env'] = 'local';
        $this->put('/resident/profile', $this->fields())->assertStatus(419);
        $this->post('/resident/profile/picture', ['profile_picture' => $this->image()])->assertStatus(419);
        $this->delete('/resident/profile/picture')->assertStatus(419);
    }

    public static function additionalImageFormats(): array
    {
        return [['jpg', 'image/jpeg'], ['jpeg', 'image/jpeg'], ['webp', 'image/webp']];
    }

    #[DataProvider('additionalImageFormats')]
    public function test_supported_image_formats_are_accepted(string $extension, string $mime): void
    {
        $user = $this->user();
        $fixture = $extension === 'jpeg' ? 'jpg' : $extension;
        $file = UploadedFile::fake()->createWithContent('picture.'.$extension,
            file_get_contents(base_path('tests/Fixtures/profile/picture.'.$fixture)));
        $this->actingAs($user)->post('/resident/profile/picture', ['profile_picture' => $file])->assertSessionHasNoErrors();
        $this->actingAs($user->refresh())->get('/resident/profile/picture')->assertOk()->assertHeader('content-type', $mime);
    }

    private function fields(): array
    {
        return ['name' => 'Updated Resident', 'contact_number' => '0917 123 4567', 'address' => 'Barangay Calayo, Nasugbu, Batangas'];
    }

    private function image(string $name = 'original.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private function user(UserRole $role = UserRole::Resident): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->refresh();
    }
}
