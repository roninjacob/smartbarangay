<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\GeneratedReservationDocuments;
use App\Services\PaymentCollections;
use App\Services\QrTicketIssuer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class PaymentCompletionTest extends TestCase
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
        $service = Service::create(['name' => 'Completion test', 'fee_amount' => '999.00']);
        $schedule = Schedule::create(['date' => '2026-12-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10]);
        $reservation = $owner->reservations()->make(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => 'ready_for_pickup']);
        $reservation->forceFill(['fee_snapshot' => $fee])->save();
        app(QrTicketIssuer::class)->issue($reservation);
        $this->actingAs($admin);

        return [$admin, $owner, $reservation];
    }

    private function verify(Reservation $reservation): string
    {
        $response = $this->postJson('/admin/qr-verification', ['payload' => $reservation->qrTicket->encodedPayload(), 'reservation_id' => 99999]);
        $response->assertOk()->assertJsonPath('valid', true)->assertJsonPath('reservation_url', route('admin.reservations.show', $reservation));

        return $response->json('reservation_url');
    }

    private function complete(Reservation $reservation)
    {
        return $this->patch(route('admin.reservations.status', $reservation), ['status' => 'completed',
            'expected_status' => 'ready_for_pickup', 'is_paid' => true, 'payment_status' => 'paid',
            'fee_snapshot' => '0.00', 'used_at' => now(), 'used_by' => 99999]);
    }

    public static function blockedCases(): array
    {
        $cases = [];
        foreach ([false, true] as $fromQr) {
            foreach ([['50.00', null], ['50.00', '49.99'], ['50.00', '50.01'], ['0.00', '50.00'], [null, '50.00'], ['-1.00', null]] as [$fee, $amount]) {
                $cases[] = [$fromQr, $fee, $amount];
            }
        }

        return $cases;
    }

    #[DataProvider('blockedCases')]
    public function test_unpaid_or_inconsistent_completion_is_blocked_even_after_qr_verification(bool $fromQr, ?string $fee, ?string $amount): void
    {
        [$admin, , $reservation] = $this->fixture($fee);
        if ($amount !== null) {
            (new ReservationPayment)->forceFill(['reservation_id' => $reservation->id, 'amount' => $amount,
                'paid_at' => now('UTC'), 'recorded_by' => $admin->id])->save();
        }
        $before = $reservation->fresh()->getAttributes();
        $ticket = $reservation->qrTicket->getAttributes();
        $payments = ReservationPayment::all()->toArray();
        $url = $fromQr ? $this->verify($reservation) : route('admin.reservations.show', $reservation);
        $this->get($url)->assertOk()->assertSee($amount === null && $fee === '50.00' ? 'Payment must be recorded' : 'payment integrity issue')
            ->assertDontSee('Mark Completed')->assertDontSee('value="completed"', false);
        $this->complete($reservation)->assertSessionHasErrors('status');
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        $this->assertSame($ticket, $reservation->qrTicket->fresh()->getAttributes());
        $this->assertSame($payments, ReservationPayment::all()->toArray());
        foreach (['reservation_status_histories', 'checkin_logs', 'reservation_documents'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->verify($reservation); // A rejected completion does not consume/invalidate the same QR.
    }

    public static function allowedCases(): array
    {
        return [[false, '50.00'], [true, '50.00'], [false, '0.00'], [true, '0.00'], [false, null], [true, null]];
    }

    #[DataProvider('allowedCases')]
    public function test_paid_free_and_legacy_completion_preserve_history_qr_and_saved_documents(bool $fromQr, ?string $fee): void
    {
        [$admin, $owner, $reservation] = $this->fixture($fee);
        if ($fee === '50.00') {
            $this->post(route('admin.reservations.payment.store', $reservation), ['confirm_payment' => 1])->assertSessionHasNoErrors();
        }
        $bytes = CertificatePdf::bytes();
        $path = 'reservation-documents/'.$reservation->id.'/'.str_repeat('a', 40).'.pdf';
        Storage::disk('local')->put($path, $bytes);
        $document = $reservation->document()->create(['template_revision' => str_repeat('b', 64), 'template_version' => 1,
            'template_filename' => 'Historical.pdf', 'original_filename' => 'Saved.pdf', 'stored_path' => $path,
            'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'content_hash' => hash('sha256', $bytes), 'generated_by' => $admin->id, 'generated_at' => now()]);
        $original = $document->fresh()->getAttributes();
        $payment = $reservation->payment?->getAttributes();
        $profile = $reservation->resident_snapshot;
        $collections = app(PaymentCollections::class)->summary();
        $url = $fromQr ? $this->verify($reservation) : route('admin.reservations.show', $reservation);
        $this->get($url)->assertSee('Mark Completed');
        if ($fee === null) {
            $this->get($url)->assertSee('Fee Not Recorded')->assertSee('legacy completion workflow');
        }
        $this->complete($reservation)->assertSessionHasNoErrors()->assertRedirect($url);
        $reservation->refresh();
        $this->assertSame('completed', $reservation->status->value);
        $this->assertSame($fee, $reservation->fee_snapshot);
        $this->assertSame($profile, $reservation->resident_snapshot);
        $this->assertTrue($reservation->qrTicket->isUsed());
        $history = $reservation->statusHistories()->sole();
        $this->assertSame('ready_for_pickup', $history->from_status->value);
        $this->assertSame('completed', $history->to_status->value);
        $this->assertSame($admin->id, $history->changed_by);
        $this->assertSame($admin->id, $reservation->qrTicket->used_by);
        $this->assertTrue($reservation->qrTicket->used_at->equalTo($history->changed_at));
        $this->assertSame($payment, $reservation->payment?->getAttributes());
        $this->assertSame($collections, app(PaymentCollections::class)->summary());
        $this->assertSame($original, $document->fresh()->getAttributes());
        $this->assertSame($bytes, app(GeneratedReservationDocuments::class)->bytes($reservation));
        $this->assertDatabaseCount('reservation_payments', $fee === '50.00' ? 1 : 0);
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->postJson('/admin/qr-verification', ['payload' => $reservation->qrTicket->encodedPayload()])->assertJsonPath('valid', false);
        $this->actingAs($owner)->get(route('resident.reservations.show', $reservation))->assertOk()->assertSee($fee === null ? 'Fee Not Recorded' : ($fee === '0.00' ? 'No Payment Required' : 'Paid'));
    }

    public function test_blocked_qr_can_complete_after_payment_without_auto_completion_or_collection_changes(): void
    {
        [, , $reservation] = $this->fixture();
        $this->assertSame(1, app(PaymentCollections::class)->summary()['ready_unpaid']);
        $ticket = $reservation->qrTicket->getAttributes();
        $this->verify($reservation);
        $this->complete($reservation)->assertSessionHasErrors('status');
        $this->post(route('admin.reservations.payment.store', $reservation), ['confirm_payment' => 1])->assertSessionHasNoErrors();
        $this->assertSame('ready_for_pickup', $reservation->fresh()->status->value);
        $this->assertSame($ticket, $reservation->qrTicket->fresh()->getAttributes());
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $summary = app(PaymentCollections::class)->summary();
        $this->assertSame(0, $summary['ready_unpaid']);
        $this->assertSame('50.00', $summary['today']);
        $this->verify($reservation);
        $this->complete($reservation)->assertSessionHasNoErrors();
        $this->assertSame($summary, app(PaymentCollections::class)->summary());
        $this->post(route('admin.reservations.payment.store', $reservation), ['confirm_payment' => 1])->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('reservation_payments', 1);
    }

    public static function blockedAccounts(): array
    {
        return [['guest', 302], ['resident', 403], ['unverified', 302], ['inactive', 302], ['csrf', 419]];
    }

    #[DataProvider('blockedAccounts')]
    public function test_completion_access_and_csrf_are_preserved(string $kind, int $status): void
    {
        [$admin, $owner, $reservation] = $this->fixture('0.00');
        if ($kind === 'guest') {
            auth()->logout();
        } elseif ($kind === 'resident') {
            $this->actingAs($owner);
        } elseif ($kind === 'csrf') {
            $this->app['env'] = 'local';
        } else {
            $admin->forceFill($kind === 'inactive' ? ['is_active' => false] : ['email_verified_at' => null])->save();
        }
        $this->complete($reservation)->assertStatus($status);
        $this->assertSame('ready_for_pickup', $reservation->fresh()->status->value);
        $this->assertFalse($reservation->qrTicket->fresh()->isUsed());
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public static function failingWrites(): array
    {
        return [['qr_tickets', 'UPDATE'], ['reservation_status_histories', 'INSERT']];
    }

    #[DataProvider('failingWrites')]
    public function test_paid_completion_failure_rolls_back_status_qr_history_and_preserves_payment(string $table, string $operation): void
    {
        [, , $reservation] = $this->fixture();
        $this->post(route('admin.reservations.payment.store', $reservation), ['confirm_payment' => 1])->assertSessionHasNoErrors();
        $before = $reservation->fresh()->getAttributes();
        $qr = $reservation->qrTicket->getAttributes();
        $payment = $reservation->payment->getAttributes();
        DB::statement("CREATE TRIGGER fail_completion BEFORE $operation ON $table BEGIN SELECT RAISE(ABORT, 'Simulated failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->complete($reservation);
            $this->fail('Expected rollback.');
        } catch (QueryException) {
            $this->assertSame($before, $reservation->fresh()->getAttributes());
            $this->assertSame($qr, $reservation->qrTicket->fresh()->getAttributes());
            $this->assertSame($payment, $reservation->payment->fresh()->getAttributes());
            $this->assertDatabaseCount('reservation_status_histories', 0);
        }
    }
}
