<?php

namespace Tests\Feature;

use App\Services\CollectionReports;
use App\Services\PaymentCollections;
use App\Services\RecordedEventReports;
use App\Services\ReservationReports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportIntegrityFixture;
use Tests\TestCase;

class CrossReportIntegrityTest extends TestCase
{
    use ReportIntegrityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array']);
        DB::purge('sqlite');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->initializeReports(CarbonImmutable::parse('2031-01-01', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);
        parent::tearDown();
    }

    public function test_four_reports_and_csv_reconcile_the_same_reviewed_population(): void
    {
        $records = $this->seedSharedPopulation();
        $reservation = app(ReservationReports::class)->data($this->filters, $this->period);
        $collection = app(CollectionReports::class)->data($this->filters, $this->period);
        $checkin = app(RecordedEventReports::class)->data('checkins', $this->filters, $this->period);
        $document = app(RecordedEventReports::class)->data('documents', $this->filters, $this->period);
        $this->assertSame(7, $reservation['total']);
        $this->assertSame(array_fill_keys(array_keys($records), 1), $reservation['counts']);
        $this->assertSame(5, $this->reportSchedule->occupiedReservations()->count()); // Four consuming statuses plus excluded Ready.
        $this->assertSame('0.30', $collection['amount']);
        $this->assertSame(2, $collection['count']);
        $this->assertSame(1, $collection['integrity_count']);
        $this->assertSame(1, $checkin['count']);
        $this->assertSame(1, $checkin['issues']);
        $this->assertSame(1, $document['count']);
        $this->assertSame(1, $document['issues']);
        foreach (['reservations' => $reservation, 'collections' => $collection, 'checkins' => $checkin, 'documents' => $document] as $kind => $data) {
            $this->assertSame(1, $data['excluded']);
            $rows = $this->csvRows($kind);
            $paginator = $data[match ($kind) {
                'reservations' => 'reservations', 'collections' => 'payments', default => 'events'
            }];
            $this->assertCount($paginator->total(), $rows);
            $this->assertSame($data['total'] ?? $data['count'], array_sum(array_column($data['series'], 'count')));
        }
        $this->assertSame(['0.20', '0.10'], array_column($this->csvRows('collections'), 3));
        $this->assertCount(1, $this->csvRows('collections', ['view' => 'integrity']));
        $this->assertSame('2030-12-31 16:00:00', $this->period['from']);
        $this->assertSame('2031-01-01 16:00:00', $this->period['until']);
    }

    public function test_dashboard_scope_is_operational_and_is_not_silently_relabelled_as_reviewed_revenue(): void
    {
        $this->seedSharedPopulation();
        $operational = app(PaymentCollections::class)->summary();
        $reviewed = app(CollectionReports::class)->data($this->filters, $this->period);
        $this->assertSame('999.30', $operational['today']);
        $this->assertSame(3, $operational['paid_today']);
        $this->assertSame('0.30', $reviewed['amount']);
        $this->actingAs($this->reportAdmin)->get(route('admin.home'))->assertOk()->assertViewHas('totalReservations', 8);
        $this->get(route('admin.reports.reservations', $this->filters))->assertOk()->assertViewHas('reportError', null);
    }
}
