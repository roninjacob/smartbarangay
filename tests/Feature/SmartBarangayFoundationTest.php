<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\CheckinLog;
use App\Models\QrTicket;
use App\Models\Reservation;
use App\Models\ReservationAttachment;
use App\Models\ReservationStatusHistory;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\ServiceRequirement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmartBarangayFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Always use a fresh in-memory connection, never the application's MySQL database.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');

        parent::tearDown();
    }

    public function test_defaults_and_all_relationships_can_be_persisted(): void
    {
        $reservation = $this->createReservation();
        $resident = $reservation->user;
        $admin = User::factory()->make();
        $admin->role = UserRole::Admin;
        $admin->save();
        $requirement = ServiceRequirement::create([
            'service_id' => $reservation->service_id,
            'name' => 'Proof of residence',
        ])->refresh();
        $attachment = ReservationAttachment::create([
            'reservation_id' => $reservation->id,
            'service_requirement_id' => $requirement->id,
            'original_filename' => 'proof.pdf',
            'stored_path' => 'reservations/proof.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ]);
        $ticket = QrTicket::create($this->ticketAttributes($reservation));
        $log = CheckinLog::create([
            'reservation_id' => $reservation->id,
            'scanned_ticket_code' => $ticket->ticket_code,
            'verified_by' => $admin->id,
            'verification_result' => 'valid',
            'verified_at' => now(),
        ]);
        $history = ReservationStatusHistory::create([
            'reservation_id' => $reservation->id,
            'from_status' => ReservationStatus::Pending,
            'to_status' => ReservationStatus::UnderReview,
            'changed_by' => $admin->id,
            'changed_at' => now(),
        ]);

        $this->assertSame(UserRole::Resident, $resident->role);
        $this->assertTrue($resident->is_active);
        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
        $this->assertTrue(Hash::check('password', $resident->password));
        $this->assertArrayNotHasKey('password', $resident->toArray());
        $this->assertSame(ReservationStatus::Pending, $reservation->status);
        $this->assertTrue($reservation->service->is_active);
        $this->assertTrue($reservation->schedule->is_active);
        $this->assertSame(10, $reservation->schedule->capacity);
        $this->assertSame('2026-10-12', $reservation->schedule->date->toDateString());
        $this->assertTrue($requirement->is_required);
        $this->assertSame(1024, $attachment->fresh()->file_size);
        $this->assertTrue($resident->reservations->sole()->is($reservation));
        $this->assertTrue($reservation->service->reservations->sole()->is($reservation));
        $this->assertTrue($reservation->schedule->reservations->sole()->is($reservation));
        $this->assertTrue($reservation->service->serviceRequirements->sole()->is($requirement));
        $this->assertTrue($requirement->service->is($reservation->service));
        $this->assertTrue($requirement->attachments->sole()->is($attachment));
        $this->assertTrue($reservation->attachments->sole()->is($attachment));
        $this->assertTrue($attachment->reservation->is($reservation));
        $this->assertTrue($attachment->serviceRequirement->is($requirement));
        $this->assertTrue($reservation->qrTicket->is($ticket));
        $this->assertTrue($ticket->reservation->is($reservation));
        $this->assertTrue($reservation->checkinLogs->sole()->is($log));
        $this->assertTrue($log->reservation->is($reservation));
        $this->assertTrue($log->verifier->is($admin));
        $this->assertTrue($admin->checkinLogs->sole()->is($log));
        $this->assertTrue($reservation->statusHistories->sole()->is($history));
        $this->assertTrue($history->reservation->is($reservation));
        $this->assertTrue($history->changedBy->is($admin));
        $this->assertTrue($admin->statusHistories->sole()->is($history));
        $this->assertSame(ReservationStatus::UnderReview, $history->fresh()->to_status);
        $this->assertNotNull($ticket->fresh()->generated_at);
        $this->assertNotNull($log->fresh()->verified_at);
        $this->assertNotNull($history->fresh()->changed_at);
    }

    public function test_roles_and_statuses_are_constrained_in_the_database(): void
    {
        $reservation = $this->createReservation();

        $this->assertDatabaseRejects(fn () => DB::table('users')
            ->where('id', $reservation->user_id)->update(['role' => 'staff']));
        $this->assertDatabaseRejects(fn () => DB::table('reservations')
            ->where('id', $reservation->id)->update(['status' => 'checked_in']));

        foreach (ReservationStatus::cases() as $status) {
            $reservation->update(['status' => $status]);
            $this->assertSame($status, $reservation->fresh()->status);
            $history = ReservationStatusHistory::create([
                'reservation_id' => $reservation->id,
                'to_status' => $status,
                'changed_at' => now(),
            ]);
            $this->assertSame($status, $history->fresh()->to_status);
            $this->assertNull($history->from_status);
            $this->assertNull($history->changedBy);
        }

        $history = $reservation->statusHistories()->first();
        foreach (['from_status', 'to_status'] as $column) {
            $this->assertDatabaseRejects(fn () => DB::table('reservation_status_histories')
                ->where('id', $history->id)->update([$column => 'unknown']));
        }
    }

    public function test_role_and_account_status_cannot_be_mass_assigned(): void
    {
        $user = new User;
        $this->assertFalse($user->isFillable('role'));
        $this->assertFalse($user->isFillable('is_active'));
        $this->assertTrue($user->isFillable('contact_number'));
        $this->assertTrue($user->isFillable('address'));
    }

    public function test_qr_ticket_is_unique_per_reservation_and_identifiers_are_unique(): void
    {
        $reservation = $this->createReservation();
        $ticket = QrTicket::create($this->ticketAttributes($reservation));
        $this->assertDatabaseRejects(fn () => QrTicket::create($this->ticketAttributes($reservation)));

        $second = $this->createReservation($reservation->schedule);
        $attributes = $this->ticketAttributes($second);
        $this->assertDatabaseRejects(fn () => QrTicket::create([
            ...$attributes, 'ticket_code' => $ticket->ticket_code,
        ]));
        $this->assertDatabaseRejects(fn () => QrTicket::create([
            ...$attributes, 'qr_payload' => $ticket->qr_payload,
        ]));
    }

    public function test_foreign_keys_reject_orphaned_records(): void
    {
        $reservation = $this->createReservation();
        foreach (['user_id', 'service_id', 'schedule_id'] as $column) {
            $this->assertDatabaseRejects(fn () => DB::table('reservations')
                ->where('id', $reservation->id)->update([$column => 999999]));
        }
        $this->assertDatabaseRejects(fn () => ServiceRequirement::create([
            'service_id' => 999999, 'name' => 'Missing service',
        ]));
        $this->assertDatabaseRejects(fn () => CheckinLog::create([
            'reservation_id' => 999999,
            'verification_result' => 'invalid',
            'verified_at' => now(),
        ]));
    }

    public function test_reserved_users_services_and_schedules_cannot_be_deleted(): void
    {
        $reservation = $this->createReservation();
        foreach ([$reservation->user, $reservation->service, $reservation->schedule] as $model) {
            $this->assertDatabaseRejects(fn () => $model->delete());
        }
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id]);
    }

    public function test_checkin_logs_and_status_histories_preserve_audit_records(): void
    {
        $reservation = $this->createReservation();
        $log = CheckinLog::create([
            'reservation_id' => $reservation->id,
            'verification_result' => 'valid',
            'verified_at' => now(),
        ]);
        $this->assertDatabaseRejects(fn () => $reservation->delete());
        $this->assertDatabaseHas('checkin_logs', ['id' => $log->id]);

        $other = $this->createReservation($reservation->schedule);
        $history = ReservationStatusHistory::create([
            'reservation_id' => $other->id,
            'to_status' => ReservationStatus::Pending,
            'changed_at' => now(),
        ]);
        $this->assertDatabaseRejects(fn () => $other->delete());
        $this->assertDatabaseHas('reservation_status_histories', ['id' => $history->id]);
    }

    public function test_deleted_actor_is_unlinked_without_losing_audit_records(): void
    {
        $reservation = $this->createReservation();
        $admin = User::factory()->make();
        $admin->role = UserRole::Admin;
        $admin->save();
        $log = CheckinLog::create([
            'reservation_id' => $reservation->id,
            'verified_by' => $admin->id,
            'verification_result' => 'valid',
            'verified_at' => now(),
        ]);
        $history = ReservationStatusHistory::create([
            'reservation_id' => $reservation->id,
            'changed_by' => $admin->id,
            'to_status' => ReservationStatus::Pending,
            'changed_at' => now(),
        ]);

        $admin->delete();

        $this->assertNull($log->fresh()->verified_by);
        $this->assertNull($history->fresh()->changed_by);
        $this->assertTrue($log->fresh()->reservation->is($reservation));
        $this->assertTrue($history->fresh()->reservation->is($reservation));
    }

    public function test_unknown_ticket_attempt_can_be_logged_without_a_reservation_or_verifier(): void
    {
        $log = CheckinLog::create([
            'scanned_ticket_code' => 'unknown-ticket',
            'verification_result' => 'invalid',
            'verified_at' => now(),
        ])->refresh();

        $this->assertNull($log->reservation);
        $this->assertNull($log->verifier);
        $this->assertSame('unknown-ticket', $log->scanned_ticket_code);
    }

    public function test_attachment_metadata_survives_requirement_deletion_and_dependents_cascade(): void
    {
        $reservation = $this->createReservation();
        $requirement = ServiceRequirement::create([
            'service_id' => $reservation->service_id, 'name' => 'Optional proof',
        ]);
        $attachment = ReservationAttachment::create([
            'reservation_id' => $reservation->id,
            'service_requirement_id' => $requirement->id,
            'original_filename' => 'proof.pdf',
            'stored_path' => 'reservations/proof.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ]);
        $ticket = QrTicket::create($this->ticketAttributes($reservation));
        $requirement->delete();

        $this->assertNull($attachment->fresh()->service_requirement_id);
        $this->assertSame('reservations/proof.pdf', $attachment->fresh()->stored_path);
        $reservation->delete();
        $this->assertDatabaseMissing('reservation_attachments', ['id' => $attachment->id]);
        $this->assertDatabaseMissing('qr_tickets', ['id' => $ticket->id]);
    }

    public function test_duplicate_time_slot_is_rejected(): void
    {
        $schedule = $this->createReservation()->schedule;
        $this->assertDatabaseRejects(fn () => Schedule::create([
            'date' => $schedule->date,
            'start_time' => $schedule->start_time,
            'end_time' => $schedule->end_time,
            'capacity' => 5,
        ]));
    }

    public function test_phase_one_rolls_back_and_reapplies_without_losing_existing_users(): void
    {
        $user = User::factory()->create();
        $email = $user->email;

        Artisan::call('migrate:rollback', [
            '--database' => 'sqlite',
            '--step' => DB::table('migrations')->where('migration', '>=', '2026_10_05_000001')->count(),
            '--force' => true,
        ]);

        foreach ([
            'services', 'service_requirements', 'schedules', 'reservations',
            'reservation_attachments', 'qr_tickets', 'checkin_logs', 'reservation_status_histories',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        foreach (['users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $email]);

        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);

        $this->assertSame(UserRole::Resident, $user->fresh()->role);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertNull($user->fresh()->contact_number);
        $this->assertNull($user->fresh()->address);
        $this->assertNull($user->fresh()->profile_picture);
    }

    private function createReservation(?Schedule $schedule = null): Reservation
    {
        $user = User::factory()->create();
        $service = Service::create(['name' => 'Barangay Clearance']);
        $schedule ??= Schedule::create([
            'date' => '2026-10-12',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'capacity' => 10,
        ]);

        return Reservation::create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'schedule_id' => $schedule->id,
        ])->refresh();
    }

    private function ticketAttributes(Reservation $reservation): array
    {
        return [
            'reservation_id' => $reservation->id,
            'ticket_code' => (string) Str::uuid(),
            'qr_payload' => (string) Str::uuid(),
            'generated_at' => now(),
        ];
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The database accepted a record that violates a constraint.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        }
    }
}
