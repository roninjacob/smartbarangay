<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\QrTicketIssuer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QrIssuanceTimestampTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'database.connections.sqlite.foreign_key_constraints' => true,
            'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
    }

    public static function fees(): array
    {
        return ['paid' => ['50.00'], 'free' => ['0.00'], 'legacy' => [null]];
    }

    #[DataProvider('fees')]
    public function test_original_issuance_survives_verification_arrival_payment_and_completion(?string $fee): void
    {
        $this->travelTo(now('UTC')->startOfSecond());
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $owner = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $service = Service::create(['name' => 'Issuance timestamp regression', 'fee_amount' => '50.00']);
        $schedule = Schedule::create(['date' => '2026-12-23', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10]);
        $reservation = $owner->reservations()->make(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => 'under_review']);
        $reservation->forceFill(['fee_snapshot' => $fee])->save();
        $this->actingAs($admin)->patch(route('admin.reservations.status', $reservation), [
            'expected_status' => 'under_review', 'status' => 'approved',
        ])->assertSessionHasNoErrors();
        $ticket = $reservation->qrTicket()->sole();
        $issued = $ticket->getRawOriginal('generated_at');
        $this->assertSame(now()->format('Y-m-d H:i:s'), $issued);
        $this->assertStringStartsWith('SBQ1:', $ticket->encodedPayload());
        $this->travel(1)->minutes();
        $this->assertSame($ticket->id, app(QrTicketIssuer::class)->issue($reservation)->id);
        $this->assertSame($issued, $ticket->fresh()->getRawOriginal('generated_at'));
        $verify = function () use ($ticket, $issued): void {
            $this->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload()])->assertJsonPath('valid', true);
            $this->assertSame($issued, $ticket->fresh()->getRawOriginal('generated_at'));
            $this->assertFalse($ticket->fresh()->isUsed());
        };
        $verify();
        $this->patch(route('admin.reservations.status', $reservation), ['expected_status' => 'approved',
            'status' => 'ready_for_pickup', 'confirm_ready' => 1, 'notes' => 'Synthetic certificate prepared manually.'])->assertSessionHasNoErrors();
        $this->assertSame($issued, $ticket->fresh()->getRawOriginal('generated_at'));
        $this->travel(1)->minutes();
        $verify();
        $this->post(route('admin.reservations.check-in.store', $reservation), ['confirm_checkin' => 1])->assertSessionHasNoErrors();
        $this->assertSame($issued, $ticket->fresh()->getRawOriginal('generated_at'));
        $this->assertFalse($ticket->fresh()->isUsed());
        $arrival = $reservation->checkinLogs()->sole()->getAttributes();
        $complete = fn () => $this->patch(route('admin.reservations.status', $reservation), [
            'expected_status' => 'ready_for_pickup', 'status' => 'completed',
        ]);
        if ($fee === '50.00') {
            $historyCount = $reservation->statusHistories()->count();
            $complete()->assertSessionHasErrors('status');
            $this->assertSame($issued, $ticket->fresh()->getRawOriginal('generated_at'));
            $this->assertFalse($ticket->fresh()->isUsed());
            $this->assertSame(ReservationStatus::ReadyForPickup, $reservation->fresh()->status);
            $this->assertSame($historyCount, $reservation->statusHistories()->count());
            $this->post(route('admin.reservations.payment.store', $reservation), ['confirm_payment' => 1])->assertSessionHasNoErrors();
            $this->assertSame('50.00', $reservation->fresh()->payment->amount);
            $this->assertSame($issued, $ticket->fresh()->getRawOriginal('generated_at'));
            $this->assertFalse($ticket->fresh()->isUsed());
            $this->assertSame(ReservationStatus::ReadyForPickup, $reservation->fresh()->status);
        }
        $verify();
        $this->travel(1)->minutes();
        $complete()->assertSessionHasNoErrors();
        $ticket->refresh();
        $this->assertSame($issued, $ticket->getRawOriginal('generated_at'));
        $this->assertTrue($ticket->isUsed());
        $this->assertSame(now()->format('Y-m-d H:i:s'), $ticket->getRawOriginal('used_at'));
        $this->assertSame($admin->id, $ticket->used_by);
        $this->assertSame(ReservationStatus::Completed, $reservation->fresh()->status);
        $this->assertSame($arrival, $reservation->checkinLogs()->sole()->getAttributes());
        $this->assertDatabaseCount('qr_tickets', 1);
        $this->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload()])->assertJsonPath('valid', false);
        $this->assertSame($issued, $ticket->fresh()->getRawOriginal('generated_at'));
    }

    public static function drivers(): array
    {
        return [['mysql'], ['mariadb'], ['sqlite']];
    }

    #[DataProvider('drivers')]
    public function test_repair_targets_only_the_production_column_and_documents_the_unsafe_reverse(string $driver): void
    {
        $migration = require database_path('migrations/2026_10_16_000022_fix_qr_ticket_generated_at_update_behavior.php');
        DB::shouldReceive('getDriverName')->twice()->andReturn($driver);
        if ($driver === 'sqlite') {
            DB::shouldReceive('statement')->never();
        } else {
            DB::shouldReceive('statement')->once()->with('ALTER TABLE `qr_tickets` MODIFY `generated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP')->andReturn(true);
            DB::shouldReceive('statement')->once()->with('ALTER TABLE `qr_tickets` MODIFY `generated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')->andReturn(true);
        }
        $migration->up();
        $migration->down();
    }
}
