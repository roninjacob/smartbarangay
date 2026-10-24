<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\ReportCsvFile;
use App\Services\ReportingExclusions;
use App\Services\ReportQueries;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportCsvExportsTest extends TestCase
{
    private string $manifest;

    private User $admin;

    private User $resident;

    private Service $service;

    private Schedule $schedule;

    private array $staged = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 03:00:00', 'UTC'));
        $this->manifest = tempnam(sys_get_temp_dir(), 'sb-csv-manifest-');
        config(['reporting.manifest' => $this->manifest]);
        $this->writeManifest();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->resident = User::factory()->create(['name' => 'Synthetic Resident', 'is_active' => true]);
        $this->service = Service::create(['name' => 'Synthetic Service', 'fee_amount' => '999.00']);
        $this->schedule = Schedule::create(['date' => '2027-01-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 10000]);
        $this->app->bind(ReportCsvFile::class, function () {
            $file = new ReportCsvFile;
            $this->staged[] = ['path' => stream_get_meta_data($file->handle())['uri'], 'ref' => \WeakReference::create($file)];

            return $file;
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);
        $this->travelBack();
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    private function writeManifest(array $entries = [], array $overrides = []): void
    {
        file_put_contents($this->manifest, json_encode(array_replace(['version' => 1, 'reviewed' => true,
            'binding' => app(ReportingExclusions::class)->binding(), 'excluded_reservations' => $entries], $overrides)));
        clearstatcache();
    }

    private function fixture(string $kind, string $time = '2026-10-24 00:00:00', array $overrides = [], string $amount = '0.10'): Reservation
    {
        $r = Reservation::create(['user_id' => $this->resident->id, 'service_id' => $this->service->id,
            'schedule_id' => $this->schedule->id, 'status' => 'completed']);
        $r->forceFill(['fee_snapshot' => $amount, 'created_at' => $kind === 'reservations' ? $time : '2025-01-01 00:00:00', 'admin_notes' => 'PRIVATE_INTERNAL_NOTE'])->save();
        if ($kind !== 'reservations') {
            $data = match ($kind) {
                'collections' => ['reservation_id' => $r->id, 'paid_at' => $time, 'amount' => $amount, 'recorded_by' => $this->admin->id],
                'checkins' => ['reservation_id' => $r->id, 'verified_at' => $time, 'verification_result' => 'checked_in', 'verified_by' => $this->admin->id, 'scanned_ticket_code' => 'PRIVATE_QR_TOKEN'],
                'documents' => ['reservation_id' => $r->id, 'generated_at' => $time, 'generated_by' => $this->admin->id, 'mime_type' => 'application/pdf', 'file_size' => 512,
                    'content_hash' => str_repeat('a', 64), 'template_revision' => str_repeat('b', 64), 'template_version' => 1,
                    'template_filename' => 'master.pdf', 'original_filename' => 'certificate.pdf', 'stored_path' => 'reservation-documents/'.$r->id.'/'.str_repeat('A', 40).'.pdf'],
            };
            DB::table(match ($kind) {
                'collections' => 'reservation_payments', 'checkins' => 'checkin_logs', 'documents' => 'reservation_documents'
            })
                ->insert(array_replace($data, $overrides));
        }

        return $r;
    }

    private function tuple(Reservation $r): array
    {
        return ['reservation_id' => $r->id, 'user_id' => $r->user_id, 'service_id' => $r->service_id,
            'schedule_id' => $r->schedule_id, 'created_at' => $r->getRawOriginal('created_at')];
    }

    private function download(string $kind, array $filters = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.reports.'.$kind.'.export', $filters));
    }

    private function rows($response): array
    {
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertSame(strlen($content), (int) $response->headers->get('Content-Length'));
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);
        foreach ($this->staged as $file) {
            clearstatcache(true, $file['path']);
            $this->assertFileDoesNotExist($file['path']);
        }

        return $rows;
    }

    public static function kinds(): array
    {
        return [['reservations'], ['collections'], ['checkins'], ['documents']];
    }

    #[DataProvider('kinds')]
    public function test_exports_are_active_verified_admin_only_and_head_does_not_stage(string $kind): void
    {
        $url = route('admin.reports.'.$kind.'.export');
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->resident)->get($url)->assertForbidden();
        $this->admin->forceFill(['email_verified_at' => null])->save();
        $this->download($kind)->assertRedirect(route('verification.notice'));
        $this->admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->download($kind)->assertRedirect(route('login'));
        $this->admin->forceFill(['is_active' => true])->save();
        $this->actingAs($this->admin)->head($url)->assertOk();
        $this->assertSame([], $this->staged);
        $rows = $this->rows($this->download($kind));
        $this->assertCount(1, $rows);
    }

    public static function boundaries(): array
    {
        $rows = [];
        foreach (self::kinds() as [$kind]) {
            foreach ([
                [[], '2026-09-30 16:00:00', '2026-10-31 16:00:00'],
                [['period' => 'today'], '2026-10-23 16:00:00', '2026-10-24 16:00:00'],
                [['period' => 'yesterday'], '2026-10-22 16:00:00', '2026-10-23 16:00:00'],
                [['period' => 'week'], '2026-10-18 16:00:00', '2026-10-25 16:00:00'],
                [['period' => 'date', 'date' => '2026-01-01'], '2025-12-31 16:00:00', '2026-01-01 16:00:00'],
                [['period' => 'custom', 'start' => '2026-02-28', 'end' => '2026-03-01'], '2026-02-27 16:00:00', '2026-03-01 16:00:00'],
            ] as [$filters, $from, $until]) {
                $rows[] = [$kind, $filters, $from, $until];
            }
        }

        return $rows;
    }

    #[DataProvider('boundaries')]
    public function test_csv_uses_exact_ui_population_date_boundaries_and_all_pages(string $kind, array $filters, string $from, string $until): void
    {
        $first = $this->fixture($kind, $from);
        $last = $this->fixture($kind, CarbonImmutable::parse($until, 'UTC')->subSecond()->format('Y-m-d H:i:s'));
        $this->fixture($kind, CarbonImmutable::parse($from, 'UTC')->subSecond()->format('Y-m-d H:i:s'));
        $this->fixture($kind, $until);
        $data = $this->actingAs($this->admin)->get(route('admin.reports.'.$kind, $filters))->viewData('report');
        $this->assertSame(2, $kind === 'reservations' ? $data['total'] : $data['count']);
        $rows = $this->rows($this->download($kind, [...$filters, 'page' => 99]));
        $this->assertCount(3, $rows);
        $this->assertSame('Request #'.$last->id, $rows[1][0]);
        $this->assertSame('Request #'.$first->id, $rows[2][0]);
        $timeIndex = $kind === 'collections' ? 4 : 3;
        $this->assertSame(CarbonImmutable::parse($from, 'UTC')->timezone('Asia/Manila')->toIso8601String(), $rows[2][$timeIndex]);
    }

    #[DataProvider('kinds')]
    public function test_csv_exports_all_records_with_bounded_keyset_batches_and_stable_ties(string $kind): void
    {
        config(['reporting.exports.batch_size' => 7]);
        $ids = [];
        for ($i = 0; $i < 23; $i++) {
            $ids[] = $this->fixture($kind)->id;
        }
        $old = $this->fixture($kind, '2026-10-23 00:00:00');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $rows = $this->rows($this->download($kind, ['page' => 2]));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(25, $rows);
        $this->assertSame(array_merge(array_map(fn ($id) => 'Request #'.$id, array_reverse($ids)), ['Request #'.$old->id]), array_column(array_slice($rows, 1), 0));
        $batches = array_filter($queries, fn ($q) => str_contains($q['query'], 'limit 7'));
        $this->assertCount(5, $batches);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select /i', $query['query']);
            $this->assertStringNotContainsString('offset', strtolower($query['query']));
        }
    }

    #[DataProvider('kinds')]
    public function test_exact_exclusions_not_names_or_current_activation_control_exports(string $kind): void
    {
        $excluded = $this->fixture($kind);
        $kept = $this->fixture($kind);
        $this->writeManifest([$this->tuple($excluded)]);
        $this->resident->update(['name' => 'TEST legitimate resident', 'is_active' => false]);
        $this->service->update(['is_active' => false]);
        $this->schedule->update(['date' => '2028-01-01']);
        $rows = $this->rows($this->download($kind));
        $this->assertCount(2, $rows);
        $this->assertSame('Request #'.$kept->id, $rows[1][0]);
        $this->assertSame('TEST legitimate resident', $rows[1][1]);
    }

    public static function manifestFailures(): array
    {
        $cases = [];
        foreach (self::kinds() as [$kind]) {
            foreach (['missing', 'malformed', 'duplicate', 'identity', 'binding', 'unreviewed', 'object'] as $case) {
                $cases[] = [$kind, $case];
            }
        }

        return $cases;
    }

    #[DataProvider('manifestFailures')]
    public function test_bad_manifest_fails_before_download_even_for_out_of_period_fixtures(string $kind, string $case): void
    {
        $r = $this->fixture($kind, '2025-01-01');
        $this->fixture($kind);
        $entry = $this->tuple($r);
        $entries = [$entry];
        $overrides = [];
        if ($case === 'duplicate') {
            $entries[] = $entry;
        }
        if ($case === 'identity') {
            $entries[0]['created_at'] = '2025-01-02 00:00:00';
        }
        if ($case === 'binding') {
            $overrides['binding'] = [];
        }
        if ($case === 'unreviewed') {
            $overrides['reviewed'] = false;
        }
        if ($case === 'object') {
            $overrides['excluded_reservations'] = new \stdClass;
        }
        $this->writeManifest($entries, $overrides);
        if ($case === 'missing') {
            unlink($this->manifest);
        }
        if ($case === 'malformed') {
            file_put_contents($this->manifest, '{PRIVATE_MANIFEST');
        }
        $this->download($kind)->assertRedirect(route('admin.reports.'.$kind, ['period' => 'month']))->assertSessionHasErrors('export')->assertHeaderMissing('Content-Disposition');
        foreach ($this->staged as $file) {
            $this->assertFileDoesNotExist($file['path']);
        }
    }

    #[DataProvider('kinds')]
    public function test_bad_filters_do_not_fall_back_to_unfiltered_csv(string $kind): void
    {
        foreach ([['period' => 'unknown'], ['period' => 'date', 'date' => '2026-02-30'], ['period' => 'custom', 'start' => '2026-10-25', 'end' => '2026-10-24']] as $filters) {
            $this->download($kind, $filters)->assertRedirect(route('admin.reports.'.$kind))->assertSessionHasErrors()->assertHeaderMissing('Content-Disposition');
        }
        $this->assertSame([], $this->staged);
    }

    public function test_collection_partitions_distinct_filenames_and_exact_decimal_amounts(): void
    {
        $this->fixture('collections', amount: '0.10');
        $this->fixture('collections', amount: '0.20');
        $bad = $this->fixture('collections', overrides: ['amount' => '999.99']);
        $validResponse = $this->download('collections');
        $validResponse->assertHeader('Content-Disposition', 'attachment; filename=smartbarangay-collections-valid-payments-2026-10-01-to-2026-10-31.csv');
        $valid = $this->rows($validResponse);
        $this->assertCount(3, $valid);
        $this->assertTrue(BigDecimal::of($valid[1][3])->plus($valid[2][3])->isEqualTo('0.30'));
        $integrityResponse = $this->download('collections', ['view' => 'integrity']);
        $integrityResponse->assertHeader('Content-Disposition', 'attachment; filename=smartbarangay-collections-integrity-issues-2026-10-01-to-2026-10-31.csv');
        $issues = $this->rows($integrityResponse);
        $this->assertCount(2, $issues);
        $this->assertSame('Request #'.$bad->id, $issues[1][0]);
        $this->assertSame('Payment integrity issue', $issues[1][6]);
    }

    public function test_event_warnings_legacy_logs_and_incomplete_documents_are_not_exported(): void
    {
        $checkin = $this->fixture('checkins');
        $this->fixture('checkins', overrides: ['reservation_id' => null]);
        $this->fixture('checkins', overrides: ['verification_result' => 'valid']);
        $doc = $this->fixture('documents');
        $this->fixture('documents', overrides: ['content_hash' => null]);
        $this->fixture('documents', overrides: ['mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
        foreach (['checkins' => $checkin, 'documents' => $doc] as $kind => $r) {
            $rows = $this->rows($this->download($kind));
            $this->assertCount(2, $rows);
            $this->assertSame('Request #'.$r->id, $rows[1][0]);
        }
    }

    public static function formulas(): array
    {
        return array_map(fn ($value) => [$value], ['=1+1', '+SUM(1,2)', '-1+2', '@SUM(1,2)', " \t=1", "\r=1", "\n=1", "\tordinary", "\u{200B}=1", "\u{FF1D}1+1"]);
    }

    #[DataProvider('formulas')]
    public function test_formula_cells_are_neutralized_but_amounts_remain_numeric(string $formula): void
    {
        $this->resident->update(['name' => $formula]);
        $this->service->update(['name' => $formula]);
        $this->admin->update(['name' => $formula]);
        $this->fixture('collections');
        $rows = $this->rows($this->download('collections'));
        foreach ([1, 2, 5] as $column) {
            $this->assertSame("'".$formula, $rows[1][$column]);
        }
        $this->assertSame('0.10', $rows[1][3]);
    }

    #[DataProvider('kinds')]
    public function test_unicode_quotes_newlines_privacy_and_read_only_behavior(string $kind): void
    {
        $name = "Calo\u{00f1}o, \"Resident\"\nSecond line";
        $this->resident->update(['name' => $name]);
        $this->fixture($kind);
        $fingerprint = function () {
            $result = [];
            foreach (['users', 'services', 'schedules', 'reservations', 'reservation_documents', 'checkin_logs', 'reservation_payments', 'reservation_status_histories', 'qr_tickets', 'notifications'] as $table) {
                $result[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()));
            }

            return $result;
        };
        $before = $fingerprint();
        $rows = $this->rows($this->download($kind));
        $this->assertSame($name, $rows[1][1]);
        $csv = json_encode($rows);
        foreach (['PRIVATE_INTERNAL_NOTE', 'PRIVATE_QR_TOKEN', 'reservation-documents/', 'password', 'content_hash', 'scanned_ticket_code'] as $secret) {
            $this->assertStringNotContainsString($secret, $csv);
        }
        $this->assertSame($before, $fingerprint());
    }

    public function test_limits_and_reconciliation_errors_clean_staging_and_do_not_download(): void
    {
        $this->fixture('reservations');
        config(['reporting.exports.max_bytes' => 1]);
        $this->download('reservations')->assertRedirect()->assertSessionHasErrors('export')->assertHeaderMissing('Content-Disposition');
        $this->assertFileDoesNotExist($this->staged[0]['path']);
        config(['reporting.exports.max_bytes' => 26214400, 'reporting.exports.max_seconds' => 0.001]);
        $this->app->instance(ReportQueries::class, new class extends ReportQueries
        {
            public function build(string $kind, array $period, array $ids): array
            {
                usleep(10000);

                return parent::build($kind, $period, $ids);
            }
        });
        $this->download('reservations')->assertRedirect()->assertSessionHasErrors('export')->assertHeaderMissing('Content-Disposition');
        foreach ($this->staged as $file) {
            $this->assertFileDoesNotExist($file['path']);
        }
        config(['reporting.exports.max_seconds' => 60]);
        $this->app->instance(ReportQueries::class, new class extends ReportQueries
        {
            public function details(string $kind, Builder|QueryBuilder $query): Builder|QueryBuilder
            {
                return parent::details($kind, $query)->whereRaw('1 = 0');
            }
        });
        $this->download('reservations')->assertRedirect()->assertSessionHasErrors('export')->assertHeaderMissing('Content-Disposition');
        foreach ($this->staged as $file) {
            $this->assertFileDoesNotExist($file['path']);
        }
    }

    public function test_large_export_uses_default_500_row_batches_without_accumulating_records(): void
    {
        $records = [];
        for ($i = 0; $i < 1001; $i++) {
            $records[] = ['user_id' => $this->resident->id, 'service_id' => $this->service->id,
                'schedule_id' => $this->schedule->id, 'status' => 'completed', 'created_at' => '2026-10-24 00:00:00'];
            if (count($records) === 200) {
                DB::table('reservations')->insert($records);
                $records = [];
            }
        }
        DB::table('reservations')->insert($records);
        unset($records);
        $batches = 0;
        DB::listen(function ($query) use (&$batches) {
            if (str_contains($query->sql, 'limit 500')) {
                $batches++;
            }
        });
        $baseline = memory_get_usage(true);
        $rows = $this->rows($this->download('reservations'));
        $this->assertCount(1002, $rows);
        $this->assertSame(4, $batches);
        $this->assertSame('Request #1001', $rows[1][0]);
        $this->assertSame('Request #1', $rows[1001][0]);
        $this->assertLessThan(16 * 1024 * 1024, memory_get_usage(true) - $baseline);
    }

    public function test_unknown_reservation_status_is_rejected_not_exported(): void
    {
        $r = $this->fixture('reservations');
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('reservations')->where('id', $r->id)->update(['status' => 'unknown']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $this->download('reservations')->assertRedirect()->assertSessionHasErrors('export')->assertHeaderMissing('Content-Disposition');
    }
}
