<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus as Status;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationGapClosureTest extends TestCase
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
        Storage::fake('public');
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_valid_required_and_optional_uploads_are_private_owned_and_replay_safe(): void
    {
        [$user, $service, $schedule, $token] = $this->draft();
        $required = $service->serviceRequirements()->create(['name' => 'Valid ID']);
        $optional = $service->serviceRequirements()->create(['name' => 'Optional form', 'is_required' => false]);
        $file = $this->png('Resident-valid-ID-with-a-long-safe-display-name.png');
        $this->get('/resident/reservations/confirm')->assertSee('multipart/form-data', false)->assertSee('Valid ID')->assertSee('Optional');
        $this->post('/resident/reservations', ['confirmation_token' => $token, 'attachments' => [$required->id => $file]])->assertSessionHas('status');
        $reservation = Reservation::sole();
        $attachment = $reservation->attachments()->sole();
        $this->assertSame($user->id, $reservation->user_id);
        $this->assertSame($required->id, $attachment->service_requirement_id);
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertMatchesRegularExpression('#^reservation-attachments/'.$reservation->id.'/[A-Za-z0-9]{40}\.png$#', $attachment->stored_path);
        Storage::disk('local')->assertExists($attachment->stored_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertFalse($optional->attachments()->exists());
        $this->get(route('resident.reservations.show', $reservation))->assertSee($attachment->original_filename)->assertDontSee($attachment->stored_path, false);
        $response = $this->get(route('resident.reservations.attachments.download', [$reservation, $attachment]))->assertOk();
        $this->assertSame(file_get_contents($file->getRealPath()), $response->streamedContent());
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertSessionHasErrors();
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_attachments', 1);
    }

    public static function invalidFiles(): array
    {
        return [['missing'], ['extension'], ['spoof'], ['mismatch'], ['size'], ['svg']];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_or_missing_required_files_create_no_records_or_files(string $kind): void
    {
        [$user, $service, $schedule, $token] = $this->draft();
        $requirement = $service->serviceRequirements()->create(['name' => 'Required ID']);
        $file = match ($kind) {
            'missing' => null,
            'extension' => $this->png('id.exe'),
            'spoof' => UploadedFile::fake()->createWithContent('evil.jpg', '<?php echo "bad";'),
            'mismatch' => $this->png('id.pdf'),
            'size' => UploadedFile::fake()->create('id.pdf', 5121, 'application/pdf'),
            'svg' => UploadedFile::fake()->createWithContent('id.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
        };
        $this->from('/resident/reservations/confirm')->post('/resident/reservations', ['confirmation_token' => $token,
            'attachments' => $file ? [$requirement->id => $file] : []])->assertRedirect('/resident/reservations/confirm')->assertSessionHasErrors('attachments.'.$requirement->id);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame($token, session('reservation_wizard.confirmation_token'));
    }

    public function test_foreign_requirements_are_rejected_and_forged_owner_fields_are_ignored(): void
    {
        [$user, $service, $schedule, $token] = $this->draft();
        $foreign = Service::create(['name' => 'Other service'])->serviceRequirements()->create(['name' => 'Foreign ID']);
        $this->post('/resident/reservations', ['confirmation_token' => $token, 'attachments' => [$foreign->id => $this->png()]])->assertSessionHasErrors('attachments');
        $this->post('/resident/reservations', ['confirmation_token' => $token, 'user_id' => 999,
            'reservation_id' => 999, 'service_requirement_id' => $foreign->id])->assertSessionHas('status');
        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame($user->id, Reservation::sole()->user_id);
        $this->assertDatabaseCount('reservation_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_attachment_access_enforces_owner_role_verification_activity_and_parent_scope(): void
    {
        [$user, $service, $schedule, $token] = $this->draft();
        $requirement = $service->serviceRequirements()->create(['name' => 'ID']);
        $this->post('/resident/reservations', ['confirmation_token' => $token, 'attachments' => [$requirement->id => $this->png()]])->assertSessionHas('status');
        $reservation = Reservation::sole();
        $attachment = $reservation->attachments()->sole();
        $residentUrl = route('resident.reservations.attachments.download', [$reservation, $attachment]);
        $adminUrl = route('admin.reservations.attachments.download', [$reservation, $attachment]);
        $this->actingAs($this->user())->get($residentUrl)->assertNotFound();
        $this->get($adminUrl)->assertForbidden();
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->get($adminUrl)->assertOk();
        $this->get($residentUrl)->assertForbidden();
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Submitted requirement files')->assertDontSee($attachment->stored_path, false);
        foreach ([[$user, $residentUrl], [$admin, $adminUrl]] as [$viewer, $url]) {
            $viewer->forceFill(['email_verified_at' => null])->save();
            $this->actingAs($viewer)->get($url)->assertRedirect(route('verification.notice'));
            $viewer->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
            $this->actingAs($viewer)->get($url)->assertRedirect(route('login'));
            $this->get($url)->assertRedirect(route('login'));
        }
        $user->forceFill(['is_active' => true])->save();
        $other = $user->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id]);
        $this->actingAs($user)->get(route('resident.reservations.attachments.download', [$other, $attachment]))->assertNotFound();
        $attachment->update(['stored_path' => '../profile-pictures/private.png']);
        $this->get($residentUrl)->assertNotFound();
    }

    public static function failingTables(): array
    {
        return [['reservations'], ['reservation_attachments']];
    }

    #[DataProvider('failingTables')]
    public function test_failed_database_writes_leave_no_incomplete_reservation_or_orphan_files(string $table): void
    {
        [$user, $service, $schedule, $token] = $this->draft();
        $required = $service->serviceRequirements()->create(['name' => 'Required']);
        DB::statement("CREATE TRIGGER fail_write BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT, 'Simulated failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->post('/resident/reservations', ['confirmation_token' => $token, 'attachments' => [$required->id => $this->png()]]);
            $this->fail('Expected write failure');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Simulated failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame($token, session('reservation_wizard.confirmation_token'));
    }

    public function test_storage_failure_after_first_file_cleans_every_new_file(): void
    {
        [$user, $service, $schedule, $token] = $this->draft();
        $first = $service->serviceRequirements()->create(['name' => 'First']);
        $second = $service->serviceRequirements()->create(['name' => 'Second']);
        $disk = Storage::disk('local');
        $diskMock = \Mockery::mock($disk)->makePartial();
        Storage::shouldReceive('disk')->with('local')->andReturn($diskMock);
        $writes = 0;
        $diskMock->shouldReceive('putFileAs')->twice()->andReturnUsing(function ($directory, $file, $filename) use ($disk, &$writes) {
            return ++$writes === 1 ? $disk->putFileAs($directory, $file, $filename) : false;
        });
        $this->post('/resident/reservations', ['confirmation_token' => $token, 'attachments' => [$first->id => $this->png(), $second->id => $this->png()]])->assertSessionHasErrors('attachments.'.$second->id);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_attachments', 0);
        $this->assertSame([], $disk->allFiles());
    }

    public static function statuses(): array
    {
        return array_map(fn ($status) => [$status, in_array($status, Status::occupyingStatuses(), true)], Status::cases());
    }

    #[DataProvider('statuses')]
    public function test_capacity_uses_current_occupancy_and_full_posts_cannot_bypass_it(Status $status, bool $occupies): void
    {
        [$user, $service, $schedule, $token] = $this->draft(1);
        $this->user()->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
        $this->assertSame($occupies ? 0 : 1, $schedule->remainingSlots());
        $response = $this->post('/resident/reservations', ['confirmation_token' => $token]);
        if ($occupies) {
            $response->assertRedirect(route('resident.reservations.schedule'))->assertSessionHasErrors('reservation');
            $this->assertDatabaseCount('reservations', 1);
            $this->get('/resident/reservations/schedule')->assertSee('Full')->assertSee('disabled', false);
            $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id])->assertRedirect(route('resident.reservations.schedule'));
        } else {
            $response->assertSessionHas('status');
            $this->assertDatabaseCount('reservations', 2);
        }
    }

    public function test_last_slot_is_available_to_only_one_of_two_prepared_resident_drafts(): void
    {
        [$first, $service, $schedule, $firstToken] = $this->draft(1);
        $firstDraft = session('reservation_wizard');
        $second = $this->user();
        $secondToken = (string) Str::uuid();
        $secondDraft = [...$firstDraft, 'resident_id' => $second->id, 'confirmation_token' => $secondToken];
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->post('/resident/reservations', ['confirmation_token' => $firstToken])->assertSessionHas('status');
        $this->actingAs($second)->withSession(['reservation_wizard' => $secondDraft])->post('/resident/reservations', ['confirmation_token' => $secondToken])->assertSessionHasErrors('reservation');
        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame(0, $schedule->remainingSlots());
        // SQLite validates serialized requests; it does not simulate concurrent MySQL row locks.
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, '"schedules"')));
    }

    public function test_empty_below_capacity_and_admin_lowering_capacity_protection(): void
    {
        [$user, $service, $schedule, $token] = $this->draft(2);
        $this->get('/resident/reservations/schedule')->assertSee('2 slots remaining');
        $this->post('/resident/reservations', ['confirmation_token' => $token])->assertSessionHas('status');
        $other = $this->user();
        $this->actingAs($other);
        $newToken = $this->prepare($service, $schedule);
        $this->post('/resident/reservations', ['confirmation_token' => $newToken])->assertSessionHas('status');
        $this->actingAs($this->user(UserRole::Admin));
        $data = ['date' => '2026-11-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 1];
        $this->put(route('admin.schedules.update', $schedule), $data)->assertSessionHasErrors('capacity');
        $this->assertSame(2, $schedule->fresh()->capacity);
        $this->get('/admin/schedules')->assertSee('2 occupied')->assertSee('0 remaining');
        $this->put(route('admin.schedules.update', $schedule), [...$data, 'capacity' => 2])->assertSessionHas('status');
    }

    public function test_legacy_reservation_details_are_preserved(): void
    {
        [$user, $service, $schedule, $token] = $this->draft();
        $reservation = $user->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id]);
        $original = $reservation->fresh()->getAttributes();
        $this->get(route('resident.reservations.show', $reservation))->assertSee('Older reservations are preserved');
        $this->actingAs($this->user(UserRole::Admin))->get(route('admin.reservations.show', $reservation))->assertSee('No requirement files were submitted');
        $this->assertSame($original, $reservation->fresh()->getAttributes());
    }

    private function png(string $name = 'id.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jxwAAAABJRU5ErkJggg=='));
    }

    private function draft(int $capacity = 5): array
    {
        $user = $this->user();
        $service = Service::create(['name' => 'Clearance']);
        $schedule = Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => $capacity]);
        $this->actingAs($user);

        return [$user, $service, $schedule, $this->prepare($service, $schedule)];
    }

    private function prepare(Service $service, Schedule $schedule): string
    {
        $this->post('/resident/reservations/service', ['service_id' => $service->id])->assertRedirect();
        $this->post('/resident/reservations/requirements')->assertRedirect();
        $this->post('/resident/reservations/schedule', ['schedule_id' => $schedule->id])->assertRedirect(route('resident.reservations.confirm'));

        return session('reservation_wizard.confirmation_token');
    }

    private function user(UserRole $role = UserRole::Resident): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }
}
