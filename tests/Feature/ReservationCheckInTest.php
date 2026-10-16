<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CheckinLog;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CheckInReservations;
use App\Services\PaymentCollections;
use App\Services\QrTicketIssuer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class ReservationCheckInTest extends TestCase
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

    private function fixture(?string $fee = '50.00'): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $owner = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $service = Service::create(['name' => 'Arrival test', 'fee_amount' => '999.00']);
        $schedule = Schedule::create(['date' => '2026-12-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10]);
        $r = $owner->reservations()->make(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => 'ready_for_pickup']);
        $r->forceFill(['fee_snapshot' => $fee])->save();
        app(QrTicketIssuer::class)->issue($r);
        $this->actingAs($admin);

        return [$admin, $owner, $r];
    }

    private function verify(Reservation $r): array
    {
        $this->postJson('/admin/qr-verification', ['payload' => $r->qrTicket->encodedPayload()])
            ->assertOk()->assertJsonPath('valid', true)->assertJsonPath('reservation_url', route('admin.reservations.show', $r));

        return session(CheckInReservations::SESSION_KEY);
    }

    private function checkin(Reservation $r, array $extra = [])
    {
        return $this->post(route('admin.reservations.check-in.store', $r), ['confirm_checkin' => 1, ...$extra]);
    }

    public static function paymentStates(): array
    {
        return [['50.00', null], ['50.00', '50.00'], ['0.00', null], [null, null], ['50.00', '49.99']];
    }

    #[DataProvider('paymentStates')]
    public function test_checkin_is_explicit_and_independent_of_payment_status_qr_history_and_documents(?string $fee, ?string $paid): void
    {
        [$admin, , $r] = $this->fixture($fee);
        $this->travelTo(now('UTC')->setDate(2026, 10, 16)->setTime(2, 0, 0));
        if ($paid !== null) {
            (new ReservationPayment)->forceFill(['reservation_id' => $r->id, 'amount' => $paid, 'paid_at' => now('UTC'), 'recorded_by' => $admin->id])->save();
        }
        $path = 'reservation-documents/'.$r->id.'/'.str_repeat('a', 40).'.pdf';
        $bytes = CertificatePdf::bytes();
        Storage::disk('local')->put($path, $bytes);
        $document = $r->document()->create(['template_revision' => str_repeat('b', 64), 'template_filename' => 'Historical.pdf',
            'original_filename' => 'Saved.pdf', 'stored_path' => $path, 'mime_type' => 'application/pdf',
            'content_hash' => hash('sha256', $bytes), 'file_size' => strlen($bytes), 'generated_at' => now(), 'generated_by' => $admin->id]);
        $original = [$r->fresh()->getAttributes(), $r->qrTicket->getAttributes(), ReservationPayment::all()->toArray(), $document->fresh()->getAttributes(), app(PaymentCollections::class)->summary()];
        $this->get(route('admin.reservations.show', $r))->assertDontSee('Confirm Check-In');
        $evidence = $this->verify($r);
        $this->assertSame($admin->id, $evidence['admin_id']);
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->get(route('admin.reservations.show', $r))->assertSee('Confirm Check-In');
        $this->checkin($r)->assertSessionHasNoErrors()->assertRedirect(route('admin.reservations.show', $r));
        $log = CheckinLog::sole();
        $this->assertSame($r->id, $log->reservation->id);
        $this->assertSame($r->id, $log->checked_in_reservation_id);
        $this->assertSame($admin->id, $log->verifier->id);
        $this->assertSame('2026-10-16 02:00:00', $log->verified_at->format('Y-m-d H:i:s'));
        $this->assertNull($log->scanned_ticket_code);
        $this->assertSame($original, [$r->fresh()->getAttributes(), $r->qrTicket->fresh()->getAttributes(), ReservationPayment::all()->toArray(), $document->fresh()->getAttributes(), app(PaymentCollections::class)->summary()]);
        $this->assertSame($bytes, Storage::disk('local')->get($path));
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $this->verify($r);
        $this->get(route('admin.reservations.show', $r))->assertSee('Already checked in')->assertSee('October 16, 2026, 10:00 AM')->assertDontSee('Confirm Check-In');
    }

    public function test_repeats_by_another_admin_preserve_original_time_actor_and_database_uniqueness(): void
    {
        [$first, , $r] = $this->fixture();
        $this->verify($r);
        $this->checkin($r)->assertSessionHasNoErrors();
        $original = CheckinLog::sole()->getAttributes();
        $this->travel(2)->minutes();
        $second = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->actingAs($second);
        $this->verify($r);
        $this->checkin($r)->assertSessionHas('status', 'Already checked in. The original arrival record is unchanged.');
        $this->assertSame($original, CheckinLog::sole()->getAttributes());
        $this->assertSame($first->id, CheckinLog::sole()->verified_by);
        foreach (['update', 'delete'] as $operation) {
            try {
                $log = CheckinLog::sole();
                $operation === 'update' ? $log->forceFill(['verified_by' => $second->id])->save() : $log->delete();
                $this->fail('Arrival was mutated.');
            } catch (\LogicException) {
                $this->assertSame($original, CheckinLog::sole()->getAttributes());
            }
        }
        $this->expectException(QueryException::class);
        CheckinLog::create(['reservation_id' => $r->id, 'verification_result' => CheckinLog::ARRIVAL, 'verified_by' => $second->id, 'verified_at' => now()]);
    }

    public static function ineligibleStatuses(): array
    {
        return array_map(fn ($s) => [$s], ['pending', 'under_review', 'approved', 'completed', 'cancelled', 'rejected']);
    }

    #[DataProvider('ineligibleStatuses')]
    public function test_status_is_rechecked_after_valid_verification(string $status): void
    {
        [, , $r] = $this->fixture();
        $this->verify($r);
        $r->update(['status' => $status]);
        $this->checkin($r)->assertSessionHasErrors('checkin');
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->assertSame($status, $r->fresh()->status->value);
        $this->assertFalse($r->qrTicket->fresh()->isUsed());
    }

    public static function invalidEvidence(): array
    {
        return array_combine(['missing', 'expired', 'other_admin', 'other_reservation', 'changed_credential', 'used_qr', 'inactive_owner', 'unverified_owner', 'invalid_scan'], [['missing'], ['expired'], ['other_admin'], ['other_reservation'], ['changed_credential'], ['used_qr'], ['inactive_owner'], ['unverified_owner'], ['invalid_scan']]);
    }

    #[DataProvider('invalidEvidence')]
    public function test_forged_or_stale_evidence_and_invalid_qr_state_cannot_check_in(string $kind): void
    {
        [$admin, $owner, $r] = $this->fixture();
        if ($kind !== 'missing') {
            $this->verify($r);
        }
        if ($kind === 'expired') {
            $this->travel(5)->minutes();
        } elseif ($kind === 'other_admin') {
            $this->actingAs(User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]));
        } elseif ($kind === 'other_reservation') {
            $r = $r->replicate();
            $r->save();
            app(QrTicketIssuer::class)->issue($r);
        } elseif ($kind === 'changed_credential') {
            $code = (string) Str::uuid();
            $r->qrTicket->update(['ticket_code' => $code, 'qr_payload' => $code]);
        } elseif ($kind === 'used_qr') {
            $r->qrTicket->forceFill(['used_at' => now(), 'used_by' => $admin->id])->save();
        } elseif ($kind === 'inactive_owner') {
            $owner->forceFill(['is_active' => false])->save();
        } elseif ($kind === 'unverified_owner') {
            $owner->forceFill(['email_verified_at' => null])->save();
        } elseif ($kind === 'invalid_scan') {
            $this->postJson('/admin/qr-verification', ['payload' => 'bad'])->assertJsonPath('valid', false);
        }
        $this->checkin($r)->assertSessionHasErrors('checkin');
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public static function forgedFields(): array
    {
        return array_map(fn ($f) => [$f], ['checked_in_at', 'checked_in_by', 'resident_id', 'reservation_id', 'status', 'qr_used', 'payment_status', 'source', 'verified_at', 'verified_by', 'verification_result']);
    }

    #[DataProvider('forgedFields')]
    public function test_client_audit_and_business_fields_are_prohibited(string $field): void
    {
        [, , $r] = $this->fixture();
        $this->verify($r);
        $this->checkin($r, [$field => 'forged'])->assertSessionHasErrors($field);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public static function blockedAccounts(): array
    {
        return [['guest', 302], ['resident', 403], ['inactive', 302], ['unverified', 302], ['csrf', 419]];
    }

    #[DataProvider('blockedAccounts')]
    public function test_access_and_csrf_remain_enforced(string $kind, int $status): void
    {
        [$admin, $owner, $r] = $this->fixture();
        $this->verify($r);
        if ($kind === 'guest') {
            auth()->logout();
        } elseif ($kind === 'resident') {
            $this->actingAs($owner);
        } elseif ($kind === 'csrf') {
            $this->app['env'] = 'local';
        } else {
            $admin->forceFill($kind === 'inactive' ? ['is_active' => false] : ['email_verified_at' => null])->save();
        }
        $this->checkin($r)->assertStatus($status);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public function test_payment_and_completion_remain_separate_after_arrival(): void
    {
        [, , $r] = $this->fixture();
        $this->verify($r);
        $this->checkin($r)->assertSessionHasNoErrors();
        $original = CheckinLog::sole()->getAttributes();
        $input = ['expected_status' => 'ready_for_pickup', 'status' => 'completed'];
        $this->patch(route('admin.reservations.status', $r), $input)->assertSessionHasErrors('status');
        $this->post(route('admin.reservations.payment.store', $r), ['confirm_payment' => 1])->assertSessionHasNoErrors();
        $this->assertSame('ready_for_pickup', $r->fresh()->status->value);
        $this->assertFalse($r->qrTicket->fresh()->isUsed());
        $this->patch(route('admin.reservations.status', $r), $input)->assertSessionHasNoErrors();
        $this->assertSame('completed', $r->fresh()->status->value);
        $this->assertTrue($r->qrTicket->fresh()->isUsed());
        $this->assertSame($original, CheckinLog::sole()->getAttributes());
    }

    public function test_write_failure_rolls_back_without_a_half_transaction(): void
    {
        [, , $r] = $this->fixture();
        $this->verify($r);
        $before = $r->fresh()->getAttributes();
        $qr = $r->qrTicket->getAttributes();
        DB::statement("CREATE TRIGGER fail_arrival BEFORE INSERT ON checkin_logs BEGIN SELECT RAISE(ABORT, 'Simulated failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->checkin($r);
            $this->fail('Expected write failure.');
        } catch (QueryException) {
            $this->assertSame($before, $r->fresh()->getAttributes());
            $this->assertSame($qr, $r->qrTicket->fresh()->getAttributes());
            $this->assertDatabaseCount('checkin_logs', 0);
        }
    }

    public function test_migration_preserves_legacy_attempts_and_only_deduplicates_operational_arrivals(): void
    {
        [, , $r] = $this->fixture();
        $migration = require database_path('migrations/2026_10_16_000021_add_operational_checkin_uniqueness.php');
        $migration->down();
        foreach ([1, 2] as $i) {
            CheckinLog::create(['reservation_id' => $r->id, 'verification_result' => 'valid', 'verified_at' => now()]);
        }
        $before = DB::table('checkin_logs')->get()->map(fn ($row) => (array) $row)->all();
        $migration->up();
        foreach (DB::table('checkin_logs')->get() as $i => $row) {
            $this->assertNull($row->checked_in_reservation_id);
            $this->assertSame($before[$i], array_diff_key((array) $row, ['checked_in_reservation_id' => true]));
        }
        $this->verify($r);
        $this->checkin($r)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('checkin_logs', 3);
    }
}
