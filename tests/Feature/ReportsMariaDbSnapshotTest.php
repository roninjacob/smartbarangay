<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Services\CollectionReports;
use App\Services\QrTicketIssuer;
use App\Services\RecordedEventReports;
use App\Services\ReservationReports;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Fixtures\CapacityMariaDb;
use Tests\Support\ReportIntegrityFixture;
use Tests\TestCase;

class ReportsMariaDbSnapshotTest extends TestCase
{
    use ReportIntegrityFixture;

    private int $port;

    private string $root;

    private bool $writerRan = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('SB_CAPACITY_TEST_PORT')) {
            $this->markTestSkipped('Requires the guarded disposable MariaDB server; SQLite cannot prove MVCC snapshots.');
        }
        $this->port = (int) getenv('SB_CAPACITY_TEST_PORT');
        $this->root = CapacityMariaDb::configure($this->port, true);
        Artisan::call('migrate', ['--database' => 'capacity_test', '--force' => true]);
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@tx_isolation AS isolation_level')->isolation_level);
        $this->initializeReports();
    }

    protected function tearDown(): void
    {
        if (isset($this->manifest)) {
            @unlink($this->manifest);
        }
        parent::tearDown();
    }

    private function writeAfterRead(string $needle, Reservation $record, string $operation): void
    {
        DB::listen(function (QueryExecuted $event) use ($needle, $record, $operation) {
            if ($this->writerRan || ! str_contains(strtolower($event->sql), $needle)) {
                return;
            }
            $this->writerRan = true;
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/report-snapshot-writer.php')], base_path(),
                ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $this->root.'/no-report-config-cache.php']);
            $process->setTimeout(20)->setInput(json_encode(['port' => $this->port, 'admin' => $this->reportAdmin->id,
                'reservation' => $record->id, 'operation' => $operation, 'clock' => now('UTC')->format('Y-m-d H:i:s')]));
            $process->mustRun();
            $this->assertSame('accepted', $process->getOutput());
        });
    }

    public function test_real_mariadb_all_four_populations_csv_and_warning_partitions_reconcile_without_writes(): void
    {
        $this->seedSharedPopulation();
        $writes = [];
        DB::listen(function (QueryExecuted $event) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $event->sql)) {
                $writes[] = strtok($event->sql, ' ');
            }
        });
        $reports = ['reservations' => app(ReservationReports::class)->data($this->filters, $this->period),
            'collections' => app(CollectionReports::class)->data($this->filters, $this->period),
            'checkins' => app(RecordedEventReports::class)->data('checkins', $this->filters, $this->period),
            'documents' => app(RecordedEventReports::class)->data('documents', $this->filters, $this->period)];
        foreach (['reservations' => 7, 'collections' => 2, 'checkins' => 1, 'documents' => 1] as $kind => $count) {
            $this->assertSame($count, $reports[$kind]['total'] ?? $reports[$kind]['count']);
            $this->assertSame(1, $reports[$kind]['excluded']);
            $this->assertCount($count, $this->csvRows($kind));
        }
        $this->assertSame('0.30', $reports['collections']['amount']);
        $this->assertSame(1, $reports['collections']['integrity_count']);
        $this->assertSame(1, $reports['checkins']['issues']);
        $this->assertSame(1, $reports['documents']['issues']);
        $this->assertCount(1, $this->csvRows('collections', ['view' => 'integrity']));
        $this->assertSame([], $writes);
    }

    public function test_collection_cards_series_and_table_keep_the_snapshot_during_a_real_payment_commit(): void
    {
        $first = $this->reportReservation();
        $this->reportPayment($first);
        $second = $this->reportReservation(fee: '0.20');
        $this->writeAfterRead('as total_amount', $second, 'payment');
        $during = app(CollectionReports::class)->data($this->filters, $this->period);
        $this->assertTrue($this->writerRan);
        $this->assertSame('0.10', $during['amount']);
        $this->assertSame(1, $during['payments']->total());
        $this->assertSame(1, array_sum(array_column($during['series'], 'count')));
        $after = app(CollectionReports::class)->data($this->filters, $this->period);
        $this->assertSame('0.30', $after['amount']);
        $this->assertSame(2, $after['count']);
    }

    public function test_confirmed_arrivals_committed_mid_report_appear_only_in_the_next_snapshot(): void
    {
        $this->reportArrival($this->reportReservation());
        $second = $this->reportReservation();
        app(QrTicketIssuer::class)->issue($second);
        $this->writeAfterRead('end) = 1', $second, 'checkin');
        $during = app(RecordedEventReports::class)->data('checkins', $this->filters, $this->period);
        $this->assertTrue($this->writerRan);
        $this->assertSame(1, $during['count']);
        $this->assertSame(1, $during['events']->total());
        $this->assertSame(2, app(RecordedEventReports::class)->data('checkins', $this->filters, $this->period)['count']);
    }

    public function test_status_counts_and_detail_rows_do_not_mix_pre_and_post_completion_snapshots(): void
    {
        $record = $this->reportReservation(fee: '0.00');
        app(QrTicketIssuer::class)->issue($record);
        $this->writeAfterRead('group by `status`', $record, 'complete');
        $during = app(ReservationReports::class)->data($this->filters, $this->period);
        $this->assertTrue($this->writerRan);
        $this->assertSame(1, $during['counts']['ready_for_pickup']);
        $this->assertSame('ready_for_pickup', $during['reservations']->first()->status->value);
        $after = app(ReservationReports::class)->data($this->filters, $this->period);
        $this->assertSame(1, $after['counts']['completed']);
        $this->assertSame(1, $after['total']);
    }

    public function test_csv_reconciles_its_prepared_snapshot_while_a_payment_commits_before_batching(): void
    {
        $this->reportPayment($this->reportReservation());
        $this->reportPayment($this->reportReservation(fee: '0.20'));
        $third = $this->reportReservation(fee: '0.40');
        $this->writeAfterRead('as total_amount', $third, 'payment');
        $rows = $this->csvRows('collections');
        $this->assertTrue($this->writerRan);
        $this->assertSame(['0.20', '0.10'], array_column($rows, 3));
        $this->assertCount(3, $this->csvRows('collections'));
        $this->assertSame('0.70', app(CollectionReports::class)->data($this->filters, $this->period)['amount']);
    }

    public function test_decimal_sum_remains_exact_above_a_single_payment_column_maximum(): void
    {
        $this->reportPayment($this->reportReservation(fee: '99999999.99'));
        $this->reportPayment($this->reportReservation(fee: '0.01'));
        $data = app(CollectionReports::class)->data($this->filters, $this->period);
        $this->assertSame('100000000.00', $data['amount']);
        $this->assertCount(2, $this->csvRows('collections'));
    }
}
