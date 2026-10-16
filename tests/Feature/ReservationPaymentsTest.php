<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\ReservationPayment;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\PaymentCollections;
use App\Services\QrTicketIssuer;
use App\Services\ReservationPayments;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationPaymentsTest extends TestCase
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

    private function fixture(string $status = 'ready_for_pickup', ?string $fee = '50.00'): array
    {
        $admin = User::factory()->create(['name' => 'Payment Recorder', 'role' => UserRole::Admin, 'is_active' => true]);
        $resident = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $service = Service::create(['name' => 'Clearance', 'fee_amount' => '100.00']);
        $schedule = Schedule::create(['date' => '2026-12-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 20]);
        $reservation = $resident->reservations()->make(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
        $reservation->forceFill(['fee_snapshot' => $fee])->save();
        $this->actingAs($admin);

        return [$admin, $resident, $reservation];
    }

    public function test_admin_records_one_historical_payment_without_touching_reservation_qr_or_documents(): void
    {
        [$admin, $resident, $reservation] = $this->fixture();
        $this->travelTo(Carbon::parse('2026-10-16 02:00:00', 'UTC'));
        app(QrTicketIssuer::class)->issue($reservation);
        $before = $reservation->fresh()->getAttributes();
        $qr = $reservation->qrTicket()->first()->getAttributes();
        $url = route('admin.reservations.payment.store', $reservation);
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Unpaid')->assertSee('Record Payment')->assertSee('Amount Due:')->assertSee('₱50.00')->assertDontSee('name="amount"', false)->assertDontSee('₱100.00');
        $this->post($url, ['confirm_payment' => '1'])->assertRedirect(route('admin.reservations.show', $reservation))->assertSessionHasNoErrors();
        $payment = ReservationPayment::sole();
        $this->assertSame($reservation->id, $payment->reservation->id);
        $this->assertSame($admin->id, $payment->recorder->id);
        $this->assertSame('50.00', $payment->amount);
        $this->assertSame('2026-10-16 02:00:00', $payment->paid_at->format('Y-m-d H:i:s'));
        $this->assertSame($before, $reservation->fresh()->getAttributes());
        $this->assertSame($qr, $reservation->qrTicket()->first()->getAttributes());
        foreach (['reservation_documents', 'checkin_logs', 'reservation_status_histories'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $reservation->service->update(['fee_amount' => '999.00']);
        $this->assertSame('50.00', $payment->fresh()->amount);
        $this->assertSame('50.00', $reservation->fresh()->fee_snapshot);
        $this->assertSame('50.00', app(PaymentCollections::class)->summary()['today']);
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Paid')->assertSee('Payment Recorder')->assertSee('October 16, 2026, 10:00 AM')->assertDontSee('Record Payment</button>', false);
        $this->actingAs($resident)->get(route('resident.reservations.show', $reservation))->assertSee('Paid')->assertSee('₱50.00')->assertDontSee('Recorded By')->assertDontSee('Payment Recorder')->assertDontSee('Record Payment');
        $foreign = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $this->actingAs($foreign)->get(route('resident.reservations.show', $reservation))->assertNotFound();
        $second = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $original = $payment->fresh()->getAttributes();
        $this->actingAs($second)->post($url, ['confirm_payment' => 1])->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('reservation_payments', 1);
        $this->assertSame($original, $payment->fresh()->getAttributes());
    }

    public static function ineligibleStatuses(): array
    {
        return array_map(fn ($s) => [$s], ['pending', 'under_review', 'approved', 'completed', 'cancelled', 'rejected']);
    }

    #[DataProvider('ineligibleStatuses')]
    public function test_only_ready_for_pickup_can_record_payment(string $status): void
    {
        [, , $reservation] = $this->fixture($status);
        $this->get(route('admin.reservations.show', $reservation))->assertDontSee('Record Payment</button>', false);
        $this->post(route('admin.reservations.payment.store', $reservation), ['confirm_payment' => 1])->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('reservation_payments', 0);
        $this->assertSame($status, $reservation->fresh()->status->value);
    }

    public static function excludedFees(): array
    {
        return [['0.00', 'No Payment Required'], [null, 'Fee Not Recorded'], ['-1.00', 'Payment integrity issue']];
    }

    #[DataProvider('excludedFees')]
    public function test_free_unknown_or_invalid_fees_do_not_create_fake_payments(?string $fee, string $label): void
    {
        [, $resident, $reservation] = $this->fixture(fee: $fee);
        $this->get(route('admin.reservations.show', $reservation))->assertSee($label)->assertDontSee('Record Payment</button>', false);
        $this->post(route('admin.reservations.payment.store', $reservation), ['confirm_payment' => 1])->assertSessionHasErrors('payment');
        $this->actingAs($resident)->get(route('resident.reservations.show', $reservation))->assertSee($label)->assertDontSee('Record Payment');
        $this->assertDatabaseCount('reservation_payments', 0);
    }

    public static function blockedAccounts(): array
    {
        return [['guest', 302], ['resident', 403], ['unverified', 302], ['inactive', 302]];
    }

    #[DataProvider('blockedAccounts')]
    public function test_payment_route_requires_active_verified_admin(string $kind, int $status): void
    {
        [$admin, $resident, $reservation] = $this->fixture();
        if ($kind === 'guest') {
            auth()->logout();
        } elseif ($kind === 'resident') {
            $this->actingAs($resident);
        } else {
            $admin->forceFill($kind === 'inactive' ? ['is_active' => false] : ['email_verified_at' => null])->save();
            $this->actingAs($admin);
        }
        $this->post(route('admin.reservations.payment.store', $reservation), ['confirm_payment' => 1])->assertStatus($status);
        $this->assertDatabaseCount('reservation_payments', 0);
    }

    public static function forgedFields(): array
    {
        return [['amount', '0.01'], ['fee_snapshot', '0.01'], ['recorded_by', 999], ['paid_at', '2000-01-01'], ['reservation_id', 999], ['payment_method', 'GCash']];
    }

    #[DataProvider('forgedFields')]
    public function test_client_cannot_choose_amount_or_audit_fields(string $field, mixed $value): void
    {
        [, , $reservation] = $this->fixture();
        $this->post(route('admin.reservations.payment.store', $reservation), ['confirm_payment' => 1, $field => $value])->assertSessionHasErrors($field);
        $this->assertDatabaseCount('reservation_payments', 0);
        $this->assertSame('50.00', $reservation->fresh()->fee_snapshot);
    }

    public function test_confirmation_csrf_missing_records_and_no_edit_or_delete_routes(): void
    {
        [, , $reservation] = $this->fixture();
        $url = route('admin.reservations.payment.store', $reservation);
        $this->post($url)->assertSessionHasErrors('confirm_payment');
        $this->post('/admin/reservations/99999/payment', ['confirm_payment' => 1])->assertNotFound();
        $this->put($url)->assertStatus(405);
        $this->delete($url)->assertStatus(405);
        $this->app['env'] = 'local';
        $this->post($url, ['confirm_payment' => 1])->assertStatus(419);
        $this->assertDatabaseCount('reservation_payments', 0);
    }

    public function test_unique_constraint_immutable_model_and_financial_foreign_keys(): void
    {
        [$admin, , $reservation] = $this->fixture();
        $payment = app(ReservationPayments::class)->record($reservation, $admin);
        $this->assertFalse($payment->isFillable('amount'));
        try {
            $duplicate = new ReservationPayment;
            $duplicate->forceFill($payment->only(['reservation_id', 'amount', 'paid_at', 'recorded_by']))->save();
            $this->fail('Unique reservation payment constraint was bypassed.');
        } catch (QueryException) {
            $this->assertDatabaseCount('reservation_payments', 1);
        }
        foreach (['edit', 'delete'] as $action) {
            try {
                $record = $payment->fresh();
                $action === 'edit' ? $record->forceFill(['amount' => '51.00'])->save() : $record->delete();
                $this->fail('Payment was mutated.');
            } catch (LogicException) {
                $this->assertSame('50.00', $payment->fresh()->amount);
            }
        }
        foreach ([['reservations', $reservation->id], ['users', $admin->id]] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail('Financial history foreign key was bypassed.');
            } catch (QueryException) {
                $this->assertDatabaseHas($table, ['id' => $id]);
            }
        }
    }

    public static function inconsistentFees(): array
    {
        return [['50.00', '49.99'], ['50.00', '50.01'], ['0.00', '50.00'], [null, '50.00']];
    }

    #[DataProvider('inconsistentFees')]
    public function test_inconsistent_payment_is_never_reported_paid_or_overwritten(?string $fee, string $amount): void
    {
        [$admin, , $reservation] = $this->fixture(fee: $fee);
        $payment = new ReservationPayment;
        $payment->forceFill(['reservation_id' => $reservation->id, 'amount' => $amount, 'paid_at' => now('UTC'), 'recorded_by' => $admin->id])->save();
        $this->assertSame(PaymentStatus::IntegrityIssue, app(ReservationPayments::class)->status($reservation->fresh()));
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Payment integrity issue')->assertDontSee('Record Payment</button>', false);
        $this->post(route('admin.reservations.payment.store', $reservation), ['confirm_payment' => 1])->assertSessionHasErrors('payment');
        $this->get('/admin/home')->assertSee('Payment integrity issues detected')->assertViewHas('collections', fn ($c) => $c['integrity_issues'] === 1 && $c['today'] === '0.00');
        $this->assertSame($amount, $payment->fresh()->amount);
    }

    public static function completionPayments(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('completionPayments')]
    public function test_completion_is_not_gated_by_payment_and_retains_existing_qr_rules(bool $paid): void
    {
        [$admin, , $reservation] = $this->fixture();
        app(QrTicketIssuer::class)->issue($reservation);
        if ($paid) {
            $this->post(route('admin.reservations.payment.store', $reservation), ['confirm_payment' => 1])->assertSessionHasNoErrors();
        }
        $this->patch(route('admin.reservations.status', $reservation), ['expected_status' => 'ready_for_pickup', 'status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $reservation->fresh()->status->value);
        $this->assertTrue($reservation->qrTicket()->first()->isUsed());
        $this->assertDatabaseCount('reservation_payments', $paid ? 1 : 0);
    }

    public function test_dashboard_uses_utc_payment_times_with_manila_day_and_month_boundaries(): void
    {
        [$admin, $resident, $reservation] = $this->fixture('completed', '100.00');
        $this->travelTo(Carbon::parse('2026-10-31 16:30:00', 'UTC')); // November 1 in Manila.
        foreach ([['2026-10-31 15:59:59', '100.00'], ['2026-10-31 16:00:00', '10.00'],
            ['2026-11-01 15:59:59', '20.00'], ['2026-11-01 16:00:00', '30.00'],
            ['2026-11-30 15:59:59', '40.00'], ['2026-11-30 16:00:00', '60.00']] as [$date, $amount]) {
            $record = $reservation->replicate();
            $record->forceFill(['fee_snapshot' => $amount])->save();
            $payment = new ReservationPayment;
            $payment->forceFill(['reservation_id' => $record->id, 'amount' => $amount, 'paid_at' => $date, 'recorded_by' => $admin->id])->save();
        }
        foreach ([['ready_for_pickup', '50.00'], ['ready_for_pickup', '0.00'], ['ready_for_pickup', null], ['approved', '75.00']] as [$status, $fee]) {
            $record = $reservation->replicate();
            $record->forceFill(['fee_snapshot' => $fee, 'status' => $status])->save();
        }
        $this->get('/admin/home')->assertOk()->assertSee('Collections Today')->assertSee('Collections This Month')
            ->assertViewHas('collections', fn ($c) => $c === ['today' => '30.00', 'month' => '100.00', 'paid_today' => 2, 'ready_unpaid' => 1, 'integrity_issues' => 0]);
        $this->actingAs($resident)->get('/resident/home')->assertDontSee('Collections Today');
    }

    public function test_existing_reservations_have_no_backfilled_payments_and_empty_collections_are_zero(): void
    {
        $this->fixture();
        $this->assertDatabaseCount('reservation_payments', 0);
        $this->get('/admin/home')->assertViewHas('collections', fn ($c) => $c['today'] === '0.00' && $c['month'] === '0.00' && $c['paid_today'] === 0 && $c['ready_unpaid'] === 1);
    }
}
