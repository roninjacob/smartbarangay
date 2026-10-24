<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\ReportingExclusions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecordedEventReportsTest extends TestCase
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
        $this->travelTo(CarbonImmutable::parse('2026-10-24 03:00:00', 'UTC'));
        $this->manifest = tempnam(sys_get_temp_dir(), 'sb-events-');
        config(['reporting.manifest' => $this->manifest]);
        $this->writeManifest();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->resident = User::factory()->create(['is_active' => true]);
        $this->service = Service::create(['name' => 'TEST legitimate service']);
        $this->schedule = Schedule::create(['date' => '2027-01-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 100]);
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

    private function event(string $kind, string $time = '2026-10-24 00:00:00', array $overrides = [], ?Service $service = null): array
    {
        $r = Reservation::create(['user_id' => $this->resident->id, 'service_id' => ($service ?? $this->service)->id,
            'schedule_id' => $this->schedule->id, 'status' => 'completed']);
        $r->forceFill(['created_at' => '2025-01-01 00:00:00', 'admin_notes' => 'SECRET_INTERNAL_NOTE'])->save();
        $row = $kind === 'checkins'
            ? ['reservation_id' => $r->id, 'verified_at' => $time, 'verification_result' => 'checked_in',
                'verified_by' => $this->admin->id, 'scanned_ticket_code' => 'SECRET_QR_CREDENTIAL']
            : ['reservation_id' => $r->id, 'generated_at' => $time, 'generated_by' => $this->admin->id,
                'mime_type' => 'application/pdf', 'file_size' => 512, 'content_hash' => str_repeat('a', 64),
                'template_revision' => str_repeat('b', 64), 'template_version' => 1, 'template_filename' => 'official.pdf',
                'original_filename' => 'certificate.pdf', 'stored_path' => 'reservation-documents/'.$r->id.'/'.str_repeat('A', 40).'.pdf'];
        $id = DB::table($kind === 'checkins' ? 'checkin_logs' : 'reservation_documents')->insertGetId(array_replace($row, $overrides));

        return [$id, $r];
    }

    private function tuple(Reservation $r): array
    {
        return ['reservation_id' => $r->id, 'user_id' => $r->user_id, 'service_id' => $r->service_id,
            'schedule_id' => $r->schedule_id, 'created_at' => $r->getRawOriginal('created_at')];
    }

    private function report(string $kind, array $filters = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.reports.'.$kind, $filters));
    }

    public static function kinds(): array
    {
        return [['checkins'], ['documents']];
    }

    #[DataProvider('kinds')]
    public function test_active_verified_admin_only(string $kind): void
    {
        $url = route('admin.reports.'.$kind);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->resident)->get($url)->assertForbidden();
        $this->admin->forceFill(['email_verified_at' => null])->save();
        $this->report($kind)->assertRedirect(route('verification.notice'));
        $this->admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->report($kind)->assertRedirect(route('login'));
        $this->admin->forceFill(['is_active' => true])->save();
        $this->report($kind)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    #[DataProvider('kinds')]
    public function test_empty_reports_and_long_ranges_are_safe(string $kind): void
    {
        $data = $this->report($kind, ['period' => 'today'])->assertOk()->assertSee('No valid recorded events')->viewData('report');
        $this->assertSame(0, $data['count']);
        $this->assertSame(0, $data['issues']);
        $this->assertSame([], $data['ranking']);
        $this->assertCount(24, $data['series']);
        foreach ([['2026-01-01', '2026-12-31', 'month'], ['2020-01-01', '2026-12-31', 'year']] as [$start, $end, $bucket]) {
            $this->assertSame($bucket, $this->report($kind, ['period' => 'custom', 'start' => $start, 'end' => $end])->viewData('report')['bucket']);
        }
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
    public function test_event_time_boundaries_and_reconciliation(string $kind, array $filters, string $from, string $until): void
    {
        [$first] = $this->event($kind, $from);
        [$last] = $this->event($kind, CarbonImmutable::parse($until, 'UTC')->subSecond()->format('Y-m-d H:i:s'));
        $this->event($kind, CarbonImmutable::parse($from, 'UTC')->subSecond()->format('Y-m-d H:i:s'));
        $this->event($kind, $until);
        $data = $this->report($kind, $filters)->assertOk()->viewData('report');
        $this->assertSame(2, $data['count']);
        $this->assertSame(0, $data['issues']);
        foreach (['series', 'services', 'ranking'] as $key) {
            $this->assertSame(2, array_sum(array_column($data[$key], 'count')));
        }
        $this->assertEqualsCanonicalizing([$first, $last], $data['events']->pluck('id')->all());
    }

    public static function invalidFilters(): array
    {
        $rows = [];
        foreach (self::kinds() as [$kind]) {
            foreach ([['period' => 'unknown'], ['period' => 'date'], ['period' => 'date', 'date' => '2026-02-30'],
                ['period' => 'custom', 'start' => '2026-10-25', 'end' => '2026-10-24'], ['page' => -1]] as $filters) {
                $rows[] = [$kind, $filters];
            }
        }

        return $rows;
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_never_show_fallback_totals(string $kind, array $filters): void
    {
        $this->event($kind);
        $this->report($kind, $filters)->assertRedirect(route('admin.reports.'.$kind))->assertSessionHasErrors();
        $response = $this->report($kind)->assertSee('Please correct the filters.')->assertDontSee('Complete Service Breakdown');
        $this->assertNull($response->viewData('report'));
    }

    public function test_only_explicit_linked_arrivals_count_and_warnings_are_period_bound(): void
    {
        [$id] = $this->event('checkins', overrides: ['verified_by' => null]);
        $this->event('checkins', overrides: ['reservation_id' => null]);
        $this->event('checkins', '2025-01-01', ['reservation_id' => null]);
        foreach (['valid', 'invalid', 'verified'] as $result) {
            $this->event('checkins', overrides: ['verification_result' => $result]);
        }
        $data = $this->report('checkins')->assertSee('1 unlinked or malformed arrival record within this event period.')->viewData('report');
        $this->assertSame(1, $data['count']);
        $this->assertSame(1, $data['issues']);
        $this->assertSame([$id], $data['events']->pluck('id')->all());
    }

    public static function documentIssues(): array
    {
        return [[['mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']],
            [['content_hash' => null]], [['content_hash' => str_repeat('A', 64)]], [['mime_type' => 'APPLICATION/PDF']], [['template_revision' => str_repeat('b', 63)."\n"]], [['content_hash' => str_repeat('g', 64)]], [['template_version' => null]],
            [['file_size' => 0]], [['template_revision' => '']], [['template_filename' => ' ']],
            [['original_filename' => '']], [['stored_path' => 'private/unsafe.pdf']],
            [['stored_path' => 'reservation-documents/1/'.str_repeat('-', 40).'.pdf']]];
    }

    #[DataProvider('documentIssues')]
    public function test_legacy_and_incomplete_documents_are_warnings_not_valid_pdfs(array $overrides): void
    {
        $this->event('documents', overrides: $overrides);
        $this->event('documents', '2025-01-01', $overrides);
        $this->event('documents');
        $data = $this->report('documents')->assertSee('1 legacy or incomplete document record within this event period.')->viewData('report');
        $this->assertSame(1, $data['count']);
        $this->assertSame(1, $data['issues']);
        $this->assertSame(1, $data['events']->total());
    }

    #[DataProvider('kinds')]
    public function test_exclusions_apply_to_valid_events_and_warnings_but_not_legitimate_inactive_records(string $kind): void
    {
        [, $excluded] = $this->event($kind);
        [, $issue] = $this->event($kind, overrides: $kind === 'documents' ? ['content_hash' => null] : []);
        [$kept] = $this->event($kind);
        $this->writeManifest([$this->tuple($excluded), $this->tuple($issue)]);
        $this->resident->update(['is_active' => false]);
        $this->service->update(['is_active' => false]);
        $this->schedule->update(['date' => '2028-01-01', 'is_active' => false]);
        $data = $this->report($kind)->viewData('report');
        $this->assertSame(1, $data['count']);
        $this->assertSame(2, $data['excluded']);
        $this->assertSame(0, $data['issues']);
        $this->assertSame([$kept], $data['events']->pluck('id')->all());
    }

    public static function manifestCases(): array
    {
        $rows = [];
        foreach (self::kinds() as [$kind]) {
            foreach (['missing', 'malformed', 'duplicate', 'wrong_identity', 'unreviewed', 'wrong_binding', 'object_list'] as $case) {
                $rows[] = [$kind, $case];
            }
        }

        return $rows;
    }

    #[DataProvider('manifestCases')]
    public function test_manifest_failure_blocks_every_component_even_for_outside_period_fixture(string $kind, string $case): void
    {
        [, $r] = $this->event($kind, '2025-01-01');
        $this->event($kind);
        $entry = $this->tuple($r);
        $entries = [$entry];
        $overrides = [];
        if ($case === 'duplicate') {
            $entries[] = $entry;
        }
        if ($case === 'wrong_identity') {
            $entries[0]['user_id']++;
        }
        if ($case === 'unreviewed') {
            $overrides['reviewed'] = false;
        }
        if ($case === 'wrong_binding') {
            $overrides['binding'] = [];
        }
        if ($case === 'object_list') {
            $overrides['excluded_reservations'] = new \stdClass;
        }
        $this->writeManifest($entries, $overrides);
        if ($case === 'missing') {
            unlink($this->manifest);
        }
        if ($case === 'malformed') {
            file_put_contents($this->manifest, '{SECRET_MANIFEST');
        }
        $response = $this->report($kind)->assertSee('This report is unavailable')->assertDontSee('Complete Service Breakdown')
            ->assertDontSee($this->manifest)->assertDontSee('SECRET_MANIFEST');
        $this->assertNull($response->viewData('report'));
    }

    #[DataProvider('kinds')]
    public function test_pagination_ranking_ties_and_filter_links(string $kind): void
    {
        $ids = [];
        for ($i = 0; $i < 17; $i++) {
            [$ids[]] = $this->event($kind);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->event($kind, service: Service::create(['name' => 'Service '.$i]));
        }
        $response = $this->report($kind, ['period' => 'today']);
        $data = $response->viewData('report');
        $this->assertSame(23, $data['count']);
        $this->assertCount(15, $data['events']);
        $this->assertCount(7, $data['services']);
        $this->assertCount(6, $data['ranking']);
        $this->assertSame('Other services', $data['ranking'][5]['name']);
        $this->assertSame(2, $data['ranking'][5]['count']);
        $this->assertStringContainsString('period=today', $data['events']->nextPageUrl());
        $page2 = $this->report($kind, ['period' => 'today', 'page' => 2])->viewData('report');
        $this->assertCount(8, $page2['events']);
        $this->assertEmpty(array_intersect($data['events']->pluck('id')->all(), $page2['events']->pluck('id')->all()));
        $this->assertSame($ids[0], $page2['events']->last()->id);
    }

    #[DataProvider('kinds')]
    public function test_read_only_private_projection_escaping_and_bounded_queries(string $kind): void
    {
        $this->resident->update(['name' => '<script>resident()</script>']);
        $this->service->update(['name' => '<script>service()</script>']);
        for ($i = 0; $i < 18; $i++) {
            $this->event($kind);
        }
        $fingerprint = function () {
            $result = [];
            foreach (['reservations', 'reservation_documents', 'checkin_logs', 'qr_tickets', 'reservation_payments', 'reservation_status_histories', 'notifications'] as $table) {
                $result[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()));
            }

            return $result;
        };
        $before = $fingerprint();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->report($kind)->assertOk()->assertSee('<script>resident()</script>')->assertDontSee('<script>resident()</script>', false)
            ->assertDontSee('SECRET_QR_CREDENTIAL')->assertDontSee('SECRET_INTERNAL_NOTE')->assertDontSee('reservation-documents/')
            ->assertDontSee('password')->assertDontSee('scanned_ticket_code');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(15, count($queries));
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select /i', $query['query']);
        }
        $this->assertSame(['id', 'reservation_id', 'occurred_at', 'resident_name', 'service_name', 'actor_name'], array_keys((array) $response->viewData('report')['events']->first()));
        $this->report($kind, ['page' => 2]);
        $this->assertSame($before, $fingerprint());
    }
}
