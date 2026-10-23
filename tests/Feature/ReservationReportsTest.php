<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\ReportingExclusions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReservationReportsTest extends TestCase
{
    private string $manifest;

    private User $admin;

    private User $resident;

    private Service $service;

    private Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
        $this->manifest = tempnam(sys_get_temp_dir(), 'sb-report-');
        config(['reporting.manifest' => $this->manifest]);
        $this->writeManifest();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->resident = User::factory()->create(['is_active' => true]);
        $this->service = Service::create(['name' => 'Barangay Clearance']);
        $this->schedule = Schedule::create(['date' => '2026-10-23', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 100]);
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(3, 0));
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);
        Model::preventLazyLoading(false);
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

    private function tuple(Reservation $reservation): array
    {
        return ['reservation_id' => $reservation->id, 'user_id' => $reservation->user_id,
            'service_id' => $reservation->service_id, 'schedule_id' => $reservation->schedule_id,
            'created_at' => $reservation->getRawOriginal('created_at')];
    }

    private function reservation(string $time = '2026-10-23 00:00:00', string $status = 'pending', ?Service $service = null): Reservation
    {
        $reservation = Reservation::create(['user_id' => $this->resident->id, 'service_id' => ($service ?? $this->service)->id,
            'schedule_id' => $this->schedule->id, 'status' => $status]);
        $reservation->forceFill(['created_at' => $time])->save();

        return $reservation->refresh();
    }

    private function report(array $filters = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.reports.reservations', $filters));
    }

    public function test_access_preserves_guest_resident_unverified_inactive_rules(): void
    {
        $url = route('admin.reports.reservations');
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->resident)->get($url)->assertForbidden();
        $this->admin->forceFill(['email_verified_at' => null])->save();
        $this->report()->assertRedirect(route('verification.notice'));
        $this->admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->report()->assertRedirect(route('login'));
    }

    public static function periods(): array
    {
        return [
            'default month' => [[], '2026-09-30 16:00:00', '2026-10-31 16:00:00'],
            'today' => [['period' => 'today'], '2026-10-22 16:00:00', '2026-10-23 16:00:00'],
            'yesterday' => [['period' => 'yesterday'], '2026-10-21 16:00:00', '2026-10-22 16:00:00'],
            'week' => [['period' => 'week'], '2026-10-18 16:00:00', '2026-10-25 16:00:00'],
            'date' => [['period' => 'date', 'date' => '2026-01-01'], '2025-12-31 16:00:00', '2026-01-01 16:00:00'],
            'range' => [['period' => 'custom', 'start' => '2026-02-28', 'end' => '2026-03-01'], '2026-02-27 16:00:00', '2026-03-01 16:00:00'],
        ];
    }

    #[DataProvider('periods')]
    public function test_all_periods_use_half_open_manila_boundaries(array $filters, string $from, string $until): void
    {
        $a = $this->reservation($from);
        $b = $this->reservation(CarbonImmutable::parse($until, 'UTC')->subSecond()->format('Y-m-d H:i:s'));
        $this->reservation(CarbonImmutable::parse($from, 'UTC')->subSecond()->format('Y-m-d H:i:s'));
        $this->reservation($until);
        $response = $this->report($filters)->assertOk()->assertViewHas('period', fn ($p) => $p['from'] === $from && $p['until'] === $until);
        $data = $response->viewData('report');
        $this->assertSame(2, $data['total']);
        $this->assertSame(2, array_sum(array_column($data['series'], 'count')));
        $this->assertSame(2, (int) $data['services']->sum('total'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $data['reservations']->pluck('id')->all());
    }

    public static function invalidFilters(): array
    {
        return [[['period' => 'unknown']], [['period' => 'date']], [['period' => 'date', 'date' => '2026-02-30']],
            [['period' => 'custom', 'start' => '2026-10-24', 'end' => '2026-10-23']],
            [['period' => 'custom', 'start' => 'bad', 'end' => '2026-10-23']], [['page' => -1]], [['period' => '']]];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_do_not_silently_render_default_totals(array $filters): void
    {
        $this->reservation();
        $this->report($filters)->assertRedirect(route('admin.reports.reservations'))->assertSessionHasErrors();
        $this->report()->assertOk()->assertViewHas('report', null)->assertDontSee('Total Requests Submitted');
    }

    public function test_current_status_is_for_submission_population_not_completion_events(): void
    {
        $included = $this->reservation('2026-10-23 00:00:00', 'completed');
        $included->statusHistories()->create(['from_status' => 'ready_for_pickup', 'to_status' => 'completed', 'changed_at' => '2026-11-01 00:00:00']);
        $older = $this->reservation('2026-09-01 00:00:00', 'completed');
        $older->statusHistories()->create(['from_status' => 'ready_for_pickup', 'to_status' => 'completed', 'changed_at' => '2026-10-23 00:00:00']);
        $this->reservation('2026-10-23 00:00:01', 'cancelled');
        $data = $this->report()->viewData('report');
        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['counts']['completed']);
        $this->assertSame(1, $data['counts']['cancelled']);
        $this->assertSame($data['total'], array_sum($data['counts']));
    }

    public function test_unknown_stored_status_blocks_report_instead_of_omitting_records(): void
    {
        $row = $this->reservation();
        // Simulate corrupted storage in this isolated SQLite connection only.
        DB::statement('PRAGMA ignore_check_constraints = ON');
        try {
            DB::table('reservations')->where('id', $row->id)->update(['status' => 'unexpected_status']);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }
        $this->report()->assertViewHas('report', null)->assertSee(ReportingExclusions::UNAVAILABLE)
            ->assertDontSee('Total Requests Submitted');
    }

    public function test_exact_exclusions_are_shared_and_inactive_test_named_records_remain(): void
    {
        $fixtureService = Service::create(['name' => 'Excluded fixture']);
        $fixture = $this->reservation('2026-10-23 00:00:00', 'approved', $fixtureService);
        $this->writeManifest([$this->tuple($fixture)]);
        $this->resident->forceFill(['is_active' => false])->save();
        $this->service->update(['name' => 'TEST legitimate inactive', 'is_active' => false]);
        $kept = $this->reservation();
        $this->reservation('2026-09-01 00:00:00');
        $response = $this->report()->assertOk()->assertSee('TEST legitimate inactive')->assertDontSee('Excluded fixture');
        $data = $response->viewData('report');
        $this->assertSame(1, $data['total']);
        $this->assertSame(1, $data['excluded']);
        $this->assertSame(0, $data['counts']['approved']);
        $this->assertSame(1, array_sum(array_column($data['series'], 'count')));
        $this->assertSame(1, (int) $data['services']->sum('total'));
        $this->assertSame([$kept->id], $data['reservations']->pluck('id')->all());
    }

    public static function badManifest(): array
    {
        return array_map(fn ($case) => [$case], ['missing', 'malformed', 'unreviewed', 'version', 'binding', 'duplicate',
            'missing_row', 'user_id', 'service_id', 'schedule_id', 'created_at', 'string_id', 'missing_field', 'not_list',
            'empty_object', 'numeric_object', 'entry_array']);
    }

    #[DataProvider('badManifest')]
    public function test_manifest_failures_never_show_totals_or_private_diagnostics(string $case): void
    {
        $row = $this->reservation('2026-09-01 00:00:00'); // Even out-of-period exclusions must validate.
        $entry = $this->tuple($row);
        $entries = [$entry];
        $override = [];
        if (in_array($case, ['user_id', 'service_id', 'schedule_id'], true)) {
            $entries[0][$case]++;
        }
        if ($case === 'created_at') {
            $entries[0]['created_at'] = '2026-09-01 00:00:01';
        }
        if ($case === 'duplicate') {
            $entries[] = $entry;
        }
        if ($case === 'missing_row') {
            $entries[0]['reservation_id'] = 999;
        }
        if ($case === 'string_id') {
            $entries[0]['reservation_id'] = (string) $row->id;
        }
        if ($case === 'missing_field') {
            unset($entries[0]['created_at']);
        }
        if ($case === 'not_list') {
            $override['excluded_reservations'] = ['unexpected' => $entry];
        }
        if ($case === 'empty_object') {
            $override['excluded_reservations'] = new \stdClass;
        }
        if ($case === 'numeric_object') {
            $override['excluded_reservations'] = (object) [$entry];
        }
        if ($case === 'entry_array') {
            $override['excluded_reservations'] = [array_values($entry)];
        }
        if ($case === 'unreviewed') {
            $override['reviewed'] = false;
        }
        if ($case === 'version') {
            $override['version'] = 2;
        }
        if ($case === 'binding') {
            $override['binding'] = ['environment' => 'another database'];
        }
        $this->writeManifest($entries, $override);
        if ($case === 'missing') {
            unlink($this->manifest);
        }
        if ($case === 'malformed') {
            file_put_contents($this->manifest, '{private malformed');
        }
        $this->report()->assertOk()->assertViewHas('report', null)->assertSee(ReportingExclusions::UNAVAILABLE)
            ->assertDontSee('Total Requests Submitted')->assertDontSee($this->manifest)->assertDontSee('private malformed');
    }

    public function test_reused_id_is_blocked_and_manifest_is_reloaded_without_cached_contents(): void
    {
        $row = $this->reservation();
        $this->writeManifest([$this->tuple($row)]);
        $this->assertSame(0, $this->report()->viewData('report')['total']);
        $row->forceFill(['created_at' => '2026-10-22 23:59:59'])->save();
        $this->report()->assertViewHas('report', null);
        $this->writeManifest(); // Explicitly reviewed empty list.
        $this->assertSame(1, $this->report()->viewData('report')['total']);
        file_put_contents($this->manifest, '{}');
        $this->report()->assertViewHas('report', null);
    }

    public function test_explicit_reviewed_empty_json_array_remains_valid(): void
    {
        $row = $this->reservation();
        $this->writeManifest();
        $this->assertStringContainsString('"excluded_reservations":[]', file_get_contents($this->manifest));
        $response = $this->report()->assertOk()->assertViewHas('reportError', null);
        $data = $response->viewData('report');
        $this->assertSame(1, $data['total']);
        $this->assertSame(0, $data['excluded']);
        $this->assertSame([$row->id], $data['reservations']->pluck('id')->all());
    }

    public function test_pagination_order_filters_ties_and_top_five(): void
    {
        $rows = [];
        for ($i = 0; $i < 17; $i++) {
            $rows[] = $this->reservation();
        }
        $this->writeManifest([$this->tuple($rows[16])]);
        $data = $this->report(['period' => 'today'])->viewData('report');
        $this->assertSame(16, $data['reservations']->total());
        $this->assertCount(15, $data['reservations']);
        $this->assertSame($rows[15]->id, $data['reservations']->first()->id);
        $this->assertStringContainsString('period=today', $data['reservations']->nextPageUrl());
        $this->assertSame($rows[0]->id, $this->report(['period' => 'today', 'page' => 2])->viewData('report')['reservations']->first()->id);
        for ($i = 0; $i < 6; $i++) {
            $this->reservation(service: Service::create(['name' => 'Service '.$i]));
        }
        $services = $this->report()->viewData('report')['services'];
        $this->assertCount(5, $services);
        $this->assertSame($this->service->id, $services->first()->id);
        $this->assertSame($services->pluck('id')->sort()->values()->all(), $services->pluck('id')->all());
    }

    public function test_private_data_escaping_query_budget_and_no_business_writes(): void
    {
        $this->resident->update(['name' => '<script>resident()</script>']);
        $this->service->update(['name' => '<script>service()</script>']);
        for ($i = 0; $i < 20; $i++) {
            $this->reservation()->update(['admin_notes' => 'private processing note', 'purpose' => 'secret purpose']);
        }
        Model::preventLazyLoading();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->report()->assertOk()->assertSee('<script>resident()</script>')->assertSee('<script>service()</script>')
            ->assertDontSee('<script>resident()</script>', false)->assertDontSee('private processing note')->assertDontSee('secret purpose')
            ->assertSee(route('admin.reservations.show', 20), false)->assertHeader('Cache-Control', 'no-store, private');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(12, count($queries));
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select /i', $query['query']);
        }
        foreach (['reservation_payments', 'checkin_logs', 'reservation_documents', 'reservation_status_histories', 'qr_tickets', 'notifications'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame(20, Reservation::where('status', 'pending')->count());
        $response->assertSee('View exact chart dates and counts')->assertSee('Recorded submissions excluding reviewed acceptance fixtures.');
    }

    public function test_empty_states_local_hour_zero_buckets_and_long_range_adaptation(): void
    {
        $this->report()->assertSee('No submissions in this period.')->assertSee('No services to rank in this period.');
        $this->reservation('2026-10-22 16:00:00');
        $data = $this->report(['period' => 'today'])->viewData('report');
        $this->assertCount(24, $data['series']);
        $this->assertSame(['label' => '2026-10-23 00:00', 'count' => 1], $data['series'][0]);
        $this->assertSame(0, $data['series'][1]['count']);
        foreach ([['2026-01-01', '2026-12-31', 'month', 12], ['2020-01-01', '2026-12-31', 'year', 7]] as [$start,$end,$bucket,$count]) {
            $data = $this->report(['period' => 'custom', 'start' => $start, 'end' => $end])->viewData('report');
            $this->assertSame($bucket, $data['bucket']);
            $this->assertCount($count, $data['series']);
        }
    }
}
