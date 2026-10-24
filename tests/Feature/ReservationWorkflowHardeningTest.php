<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\ReservationFileUploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationWorkflowHardeningTest extends TestCase
{
    private User $resident;

    private Service $service;

    private Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-11-01 08:00:00', 'Asia/Manila'));
        $this->resident = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $this->service = Service::create(['name' => 'Clearance', 'fee_amount' => '0.00', 'is_active' => true]);
        $this->schedule = Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 10, 'is_active' => true]);
        $this->actingAs($this->resident);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function prepare(): string
    {
        $this->post(route('resident.reservations.service'), ['service_id' => $this->service->id])->assertRedirect();
        $this->post(route('resident.reservations.requirements'))->assertRedirect();
        $this->post(route('resident.reservations.schedule.select'), ['schedule_id' => $this->schedule->id])->assertRedirect(route('resident.reservations.confirm'));

        return session('reservation_wizard.confirmation_token');
    }

    public function test_schedule_choices_and_forged_selection_enforce_philippine_time(): void
    {
        // UTC is still October 31; Philippine calendar date and clock must decide.
        Carbon::setTestNow(Carbon::parse('2026-10-31 16:30:00', 'UTC'));
        $past = Schedule::create(['date' => '2026-11-01', 'start_time' => '00:29:59', 'end_time' => '01:00:00', 'capacity' => 5]);
        $exact = Schedule::create(['date' => '2026-11-01', 'start_time' => '00:30:00', 'end_time' => '01:30:00', 'capacity' => 5]);
        $future = Schedule::create(['date' => '2026-11-01', 'start_time' => '00:30:01', 'end_time' => '02:00:00', 'capacity' => 5]);
        $this->post(route('resident.reservations.service'), ['service_id' => $this->service->id]);
        $this->post(route('resident.reservations.requirements'));
        $response = $this->get(route('resident.reservations.schedule'))->assertOk();
        $ids = $response->viewData('schedules')->modelKeys();
        $this->assertNotContains($past->id, $ids);
        $this->assertNotContains($exact->id, $ids);
        $this->assertContains($future->id, $ids);
        foreach ([$past, $exact] as $slot) {
            $this->post(route('resident.reservations.schedule.select'), ['schedule_id' => $slot->id])
                ->assertRedirect(route('resident.reservations.schedule'))->assertSessionHasErrors('reservation');
        }
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_confirm_and_final_submit_reject_a_previously_selected_slot_that_has_started(): void
    {
        $token = $this->prepare();
        Carbon::setTestNow(Carbon::parse('2026-11-01 09:00:00', 'Asia/Manila'));
        $this->get(route('resident.reservations.confirm'))->assertRedirect(route('resident.reservations.schedule'));
        $this->post(route('resident.reservations.store'), ['confirmation_token' => $token])
            ->assertRedirect(route('resident.reservations.schedule'))->assertSessionHasErrors('reservation');
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_requirements', 0);
    }

    public static function eligibilityChanges(): array
    {
        return [[['is_active' => false]], [['email_verified_at' => null]], [['role' => 'admin']]];
    }

    #[DataProvider('eligibilityChanges')]
    public function test_fresh_locked_account_rejects_an_inflight_eligibility_change(array $change): void
    {
        $token = $this->prepare();
        $id = $this->resident->id;
        // Controller dependency resolution occurs after middleware/FormRequest validation.
        // Simulate an already committed Admin write while the authenticated model is stale.
        $this->app->bind(ReservationFileUploads::class, function () use ($id, $change) {
            DB::table('users')->where('id', $id)->update($change);

            return new ReservationFileUploads;
        });
        $this->post(route('resident.reservations.store'), ['confirmation_token' => $token])->assertForbidden();
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_requirements', 0);
        $this->assertDatabaseCount('reservation_attachments', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_start_time_crossing_during_upload_work_rolls_back_everything_and_removes_files(): void
    {
        User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $requirement = $this->service->serviceRequirements()->create(['name' => 'ID', 'is_required' => true]);
        $token = $this->prepare();
        $this->app->bind(ReservationFileUploads::class, fn () => new class extends ReservationFileUploads
        {
            public function store(Reservation $reservation, array $files, array &$paths): void
            {
                parent::store($reservation, $files, $paths);
                Carbon::setTestNow(Carbon::parse('2026-11-01 09:00:01', 'Asia/Manila'));
            }
        });
        $this->post(route('resident.reservations.store'), ['confirmation_token' => $token, 'attachments' => [$requirement->id => $this->png()]])
            ->assertRedirect(route('resident.reservations.schedule'));
        foreach (['reservations', 'reservation_requirements', 'reservation_attachments', 'reservation_documents', 'qr_tickets', 'reservation_status_histories', 'notifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_submission_captures_all_requirements_and_links_files_to_immutable_history(): void
    {
        $required = $this->service->serviceRequirements()->create(['name' => 'Original ID', 'description' => 'Original instructions', 'is_required' => true]);
        $this->service->serviceRequirements()->create(['name' => 'Optional proof', 'is_required' => false]);
        $token = $this->prepare();
        $this->post(route('resident.reservations.store'), ['confirmation_token' => $token, 'attachments' => [$required->id => $this->png()],
            'appointment_date' => '2099-01-01', 'reservation_requirement_id' => 999])->assertRedirect(route('resident.home'));
        $reservation = Reservation::sole();
        $this->assertSame('2026-11-01', $reservation->appointment_date->toDateString());
        $this->assertSame('09:00:00', $reservation->appointment_start_time);
        $this->assertSame('10:00:00', $reservation->appointment_end_time);
        $this->assertNotNull($reservation->requirements_snapshotted_at);
        $this->assertCount(2, $reservation->requirementSnapshots);
        $snapshot = $reservation->requirementSnapshots->first();
        $attachment = $reservation->attachments->sole();
        $this->assertSame($snapshot->id, $attachment->reservation_requirement_id);
        $required->update(['name' => 'Renamed current ID', 'description' => 'Changed current instructions', 'is_required' => false]);
        DB::table('schedules')->where('id', $this->schedule->id)->update(['date' => '2027-05-01', 'start_time' => '14:00:00']);
        $this->get(route('resident.reservations.show', $reservation))->assertOk()->assertSee('Original ID')
            ->assertSee('Original instructions')->assertSee('Optional proof')->assertSee('November 1, 2026')->assertSee('9:00 AM')
            ->assertDontSee('Renamed current ID')->assertDontSee('Changed current instructions')->assertDontSee('May 1, 2027');
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->actingAs($admin)->get(route('admin.reservations.show', $reservation))->assertOk()->assertSee('Original ID')
            ->assertSee('Original instructions')->assertSee('November 1, 2026')->assertDontSee('Renamed current ID');
        $this->get(route('admin.home'))->assertOk()->assertViewHas('todayReservations', 1);
        $this->assertTrue($snapshot->fresh()->is_required);
        $this->assertSame(ReservationStatus::Pending, $reservation->fresh()->status);
    }

    public function test_empty_new_checklist_is_distinct_from_a_legacy_request(): void
    {
        $token = $this->prepare();
        $this->post(route('resident.reservations.store'), ['confirmation_token' => $token])->assertRedirect();
        $reservation = Reservation::sole();
        $this->service->serviceRequirements()->create(['name' => 'Later requirement']);
        $this->get(route('resident.reservations.show', $reservation))->assertSee('No requirements were listed when this request was submitted.')
            ->assertDontSee('Later requirement');
    }

    public function test_linked_schedule_date_and_times_are_protected_even_for_nonoccupying_reservations(): void
    {
        Reservation::create(['user_id' => $this->resident->id, 'service_id' => $this->service->id, 'schedule_id' => $this->schedule->id, 'status' => ReservationStatus::Cancelled]);
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->actingAs($admin);
        $original = ['date' => '2026-11-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10];
        foreach (['date' => '2026-11-02', 'start_time' => '08:30', 'end_time' => '10:30'] as $key => $value) {
            $this->put(route('admin.schedules.update', $this->schedule), array_replace($original, [$key => $value]))->assertSessionHasErrors('date');
        }
        $this->assertSame('2026-11-01', $this->schedule->fresh()->date->toDateString());
        $this->put(route('admin.schedules.update', $this->schedule), array_replace($original, ['capacity' => 12]))->assertSessionHasNoErrors();
        $this->assertSame(12, $this->schedule->fresh()->capacity);
        $this->get(route('admin.schedules.edit', $this->schedule))->assertSee('date and times are locked');
    }

    public function test_persisted_appointment_snapshots_cannot_be_rewritten_through_the_model(): void
    {
        $token = $this->prepare();
        $this->post(route('resident.reservations.store'), ['confirmation_token' => $token]);
        $reservation = Reservation::sole();
        $this->expectException(LogicException::class);
        $reservation->forceFill(['appointment_start_time' => '12:00:00'])->save();
    }

    public function test_persisted_requirement_snapshot_cannot_be_edited(): void
    {
        $this->service->serviceRequirements()->create(['name' => 'Optional proof', 'is_required' => false]);
        $token = $this->prepare();
        $this->post(route('resident.reservations.store'), ['confirmation_token' => $token]);
        $snapshot = Reservation::sole()->requirementSnapshots->sole();
        $this->expectException(LogicException::class);
        $snapshot->forceFill(['name' => 'Replacement'])->save();
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('id.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jxwAAAABJRU5ErkJggg=='));
    }
}
