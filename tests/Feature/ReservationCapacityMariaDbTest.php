<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\ConfigurationAudit;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\CapacityMariaDb;
use Tests\TestCase;

class ReservationCapacityMariaDbTest extends TestCase
{
    private int $port;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $value = getenv('SB_CAPACITY_TEST_PORT');
        if (! $value) {
            $this->markTestSkipped('Opt-in disposable MariaDB server required; SQLite cannot prove row locks.');
        }
        $this->port = (int) $value;
        $this->root = CapacityMariaDb::configure($this->port, true);
        Artisan::call('migrate', ['--database' => 'capacity_test', '--force' => true]);
    }

    private function schedule(int $capacity): Schedule
    {
        $last = Schedule::max('date');
        $date = now('Asia/Manila')->addYear()->toDateString();
        if ($last) {
            $date = max($date, Carbon::parse($last)->addDay()->toDateString());
        }

        return Schedule::create(['date' => $date,
            'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => $capacity])->refresh();
    }

    private function booking(User $user, Service $service, Schedule $schedule): array
    {
        return ['operation' => 'book', 'user' => $user->id, 'service' => $service->id, 'schedule' => $schedule->id];
    }

    private function race(Schedule $schedule, array $actors): array
    {
        $directory = $this->root.'/barriers/'.Str::uuid();
        mkdir($directory, 0700, true);
        $processes = [];
        DB::beginTransaction();
        try {
            Schedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            foreach ($actors as $index => $actor) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/reservation-capacity-worker.php')], base_path(),
                    ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/no-config-cache.php']);
                $process->setTimeout(30)->setInput(json_encode([...$actor, 'port' => $this->port,
                    'actor' => $index, 'marker' => $directory.'/ready-'.$index]));
                $process->start();
                $processes[] = $process;
            }
            // A same-user duplicate waits first on the user row; all other actors
            // must demonstrably reach the production schedule locking query.
            $sameUser = count(array_unique(array_column($actors, 'user'))) === 1;
            $required = $sameUser ? 1 : count($actors);
            $deadline = microtime(true) + 15;
            do {
                clearstatcache();
                $ready = count(glob($directory.'/ready-*') ?: []);
                if ($ready >= $required) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $diagnostics = array_map(fn ($process) => $process->getOutput().$process->getErrorOutput(), $processes);
            $this->assertSame($required, $ready, 'Actors did not reach the expected production lock boundary. '.implode(' | ', $diagnostics));
            DB::commit();
            $results = [];
            foreach ($processes as $index => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput());
                $result = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
                if ($actors[$index]['operation'] === 'reduce' && $result['outcome'] === 'rejected') {
                    $this->assertSame(['capacity'], $result['fields'], 'Reduction must be rejected by the occupancy floor, not a stale fixture.');
                }
                $results[] = $result['outcome'];
            }

            return $results;
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

    public function test_different_services_cannot_both_book_the_last_shared_slot(): void
    {
        $slot = $this->schedule(1);
        $actors = [];
        foreach ([1, 2] as $i) {
            $resident = User::factory()->residencyApproved()->create();
            $service = Service::create(['name' => 'Synthetic capacity '.$i, 'fee_amount' => '0.00']);
            $actors[] = $this->booking($resident, $service, $slot);
        }
        $results = $this->race($slot, $actors);
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $this->assertSame(1, $slot->occupiedReservations()->count());
        $this->assertSame(0, $slot->fresh()->remainingSlots());
    }

    public function test_separate_sessions_of_one_resident_do_not_double_consume_capacity(): void
    {
        $slot = $this->schedule(5);
        $user = User::factory()->residencyApproved()->create();
        $service = Service::create(['name' => 'Synthetic duplicate', 'fee_amount' => '0.00']);
        $actor = $this->booking($user, $service, $slot);
        $results = $this->race($slot, [$actor, $actor]);
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $this->assertSame(1, $slot->reservations()->count());
    }

    public function test_booking_and_admin_capacity_reduction_cannot_overbook(): void
    {
        $slot = $this->schedule(2);
        $service = Service::create(['name' => 'Synthetic admin race', 'fee_amount' => '0.00']);
        User::factory()->residencyApproved()->create()->reservations()->create([
            'service_id' => $service->id, 'schedule_id' => $slot->id, 'status' => 'pending']);
        $resident = User::factory()->residencyApproved()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $results = $this->race($slot, [$this->booking($resident, $service, $slot), [
            'operation' => 'reduce', 'user' => $admin->id, 'schedule' => $slot->id, 'data' => [
                'date' => $slot->date->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00',
                'capacity' => 1, 'edit_revision' => app(ConfigurationAudit::class)->revision($slot),
            ],
        ]]);
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $this->assertLessThanOrEqual($slot->fresh()->capacity, $slot->occupiedReservations()->count());
    }

    public function test_concurrent_deactivation_blocks_only_bookings_after_it_wins_the_lock(): void
    {
        $slot = $this->schedule(1);
        $service = Service::create(['name' => 'Synthetic deactivation', 'fee_amount' => '0.00']);
        $resident = User::factory()->residencyApproved()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $results = $this->race($slot, [$this->booking($resident, $service, $slot), [
            'operation' => 'disable', 'user' => $admin->id, 'schedule' => $slot->id,
        ]]);
        $this->assertSame('accepted', $results[1]);
        $this->assertFalse($slot->fresh()->is_active);
        $this->assertSame($results[0] === 'accepted' ? 1 : 0, $slot->reservations()->count());
    }

    public function test_locking_occupancy_sees_a_commit_after_an_earlier_repeatable_read_snapshot(): void
    {
        $slot = $this->schedule(1);
        $service = Service::create(['name' => 'Synthetic stale snapshot', 'fee_amount' => '0.00']);
        $resident = User::factory()->residencyApproved()->create();
        $directory = $this->root.'/barriers/'.Str::uuid();
        mkdir($directory, 0700, true);
        DB::beginTransaction();
        try {
            $this->assertSame(0, $slot->occupiedReservations()->count());
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/reservation-capacity-worker.php')], base_path(),
                ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/no-config-cache.php']);
            $process->setTimeout(20)->setInput(json_encode([...$this->booking($resident, $service, $slot),
                'port' => $this->port, 'actor' => 0, 'marker' => $directory.'/ready-0']));
            $process->mustRun();
            $this->assertSame('accepted', json_decode($process->getOutput(), true)['outcome']);
            $this->assertSame(0, $slot->occupiedReservations()->count(), 'Ordinary read should retain the earlier InnoDB snapshot.');
            $locked = Schedule::whereKey($slot->id)->lockForUpdate()->firstOrFail();
            $this->assertSame(1, $locked->lockedOccupancy(), 'Production locking read must see the new committed booking.');
        } finally {
            DB::rollBack();
        }
    }
}
