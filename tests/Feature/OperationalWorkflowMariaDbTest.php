<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\QrTicketIssuer;
use App\Services\ReservationFollowups;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\CapacityMariaDb;
use Tests\TestCase;

class OperationalWorkflowMariaDbTest extends TestCase
{
    private int $port;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('SB_CAPACITY_TEST_PORT')) {
            $this->markTestSkipped('Requires the verified Phase 5 disposable MariaDB harness.');
        }
        $this->port = (int) getenv('SB_CAPACITY_TEST_PORT');
        $this->root = CapacityMariaDb::configure($this->port, true);
        Artisan::call('migrate', ['--database' => 'capacity_test', '--force' => true]);
    }

    private function reservation(string $status): Reservation
    {
        $user = User::factory()->residencyApproved()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $service = Service::create(['name' => 'Synthetic operational race '.Str::uuid(), 'fee_amount' => '45.50']);
        $last = Schedule::max('date');
        $date = max(now('Asia/Manila')->addYear()->toDateString(), $last ? Carbon::parse($last)->addDay()->toDateString() : '2000-01-01');
        $schedule = Schedule::create(['date' => $date, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 1]);
        $record = $user->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
        $record->forceFill(['fee_snapshot' => '45.50'])->save();

        return $record->fresh();
    }

    private function race(Reservation $reservation, array $operations): array
    {
        $directory = $this->root.'/barriers/'.Str::uuid();
        mkdir($directory, 0700, true);
        $processes = [];
        $admins = [];
        foreach ($operations as $unused) {
            $admins[] = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        }
        DB::beginTransaction();
        try {
            Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            foreach ($operations as $index => $operation) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/operational-workflow-worker.php')], base_path(),
                    ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/no-config-cache.php']);
                $process->setTimeout(30)->setInput(json_encode([...$operation, 'reservation' => $reservation->id,
                    'admin' => $admins[$index]->id, 'port' => $this->port, 'actor' => $index,
                    'marker' => $directory.'/operational-'.$index]));
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            do {
                clearstatcache();
                $ready = count(glob($directory.'/operational-*') ?: []);
                if ($ready === count($operations)) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(count($operations), $ready, implode(' | ', array_map(fn ($p) => $p->getOutput().$p->getErrorOutput(), $processes)));
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            }

            return [$results, $admins];
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    private function oneWinner(array $results): int
    {
        $outcomes = array_column($results, 'outcome');
        $sorted = $outcomes;
        sort($sorted);
        $this->assertSame(['accepted', 'rejected'], $sorted);

        return array_search('accepted', $outcomes, true);
    }

    public function test_conflicting_admin_approval_and_rejection_have_one_history_and_correct_actor(): void
    {
        $record = $this->reservation('under_review');
        [$results, $admins] = $this->race($record, [['operation' => 'status', 'next' => 'approved'], ['operation' => 'status', 'next' => 'rejected']]);
        $winner = $this->oneWinner($results);
        $this->assertSame($winner === 0 ? 'approved' : 'rejected', $record->fresh()->status->value);
        $history = $record->statusHistories()->sole();
        $this->assertSame($admins[$winner]->id, $history->changed_by);
        $this->assertSame($record->fresh()->status, $history->to_status);
        $this->assertSame($winner === 0 ? 1 : 0, $record->qrTicket()->count());
        $this->assertSame(1, $record->user->notifications()->where('event_key', 'status:'.$history->id)->count());
    }

    public function test_concurrent_payments_create_one_immutable_historical_payment(): void
    {
        $record = $this->reservation('ready_for_pickup');
        [$results, $admins] = $this->race($record, [['operation' => 'payment'], ['operation' => 'payment']]);
        $winner = $this->oneWinner($results);
        $payment = $record->payment()->sole();
        $this->assertSame('45.50', $payment->amount);
        $this->assertSame($admins[$winner]->id, $payment->recorded_by);
        $this->assertSame('ready_for_pickup', $record->fresh()->status->value);
        $this->assertSame(1, $record->user->notifications()->where('event_key', 'payment:'.$payment->id)->count());
    }

    public function test_concurrent_confirmations_return_one_arrival_and_preserve_first_actor(): void
    {
        $record = $this->reservation('ready_for_pickup');
        app(QrTicketIssuer::class)->issue($record);
        [$results, $admins] = $this->race($record, [['operation' => 'checkin'], ['operation' => 'checkin']]);
        $this->assertSame(['accepted', 'accepted'], array_column($results, 'outcome'));
        $arrival = $record->checkinLogs()->sole();
        $this->assertSame([$arrival->id, $arrival->id], array_column($results, 'id'));
        $this->assertContains($arrival->verified_by, array_map(fn ($admin) => $admin->id, $admins));
        $this->assertNull($record->fresh()->qrTicket->used_at);
        $this->assertSame('ready_for_pickup', $record->fresh()->status->value);
        $this->assertSame(0, $record->statusHistories()->count());
    }

    public function test_payment_and_cancellation_review_cannot_both_succeed(): void
    {
        $record = $this->reservation('ready_for_pickup');
        $request = app(ReservationFollowups::class)->requestCancellation($record->user, $record->id, 'Synthetic cancellation.');
        [$results] = $this->race($record, [['operation' => 'payment'], ['operation' => 'cancel', 'cancellation' => $request->id]]);
        $winner = $this->oneWinner($results);
        $this->assertSame($winner === 0 ? 1 : 0, $record->payment()->count());
        $this->assertSame($winner === 0 ? 'ready_for_pickup' : 'cancelled', $record->fresh()->status->value);
        $this->assertSame($winner === 0 ? 'pending' : 'approved', $request->fresh()->status);
    }

    public function test_completion_racing_payment_never_completes_an_unpaid_request(): void
    {
        $record = $this->reservation('ready_for_pickup');
        app(QrTicketIssuer::class)->issue($record);
        [$results, $admins] = $this->race($record, [['operation' => 'payment'],
            ['operation' => 'status', 'next' => 'completed', 'expected' => 'ready_for_pickup']]);
        $this->assertSame('accepted', $results[0]['outcome']);
        $this->assertSame('45.50', $record->payment()->sole()->amount);
        $completed = $results[1]['outcome'] === 'accepted';
        $this->assertSame($completed ? 'completed' : 'ready_for_pickup', $record->fresh()->status->value);
        $this->assertSame($completed ? 1 : 0, $record->statusHistories()->count());
        $this->assertSame($completed, $record->fresh()->qrTicket->used_at !== null);
        $this->assertSame($completed ? $admins[1]->id : null, $record->fresh()->qrTicket->used_by);
    }
}
