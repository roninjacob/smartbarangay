<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\ReportingExclusions;
use App\Services\ReservationPayments;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CollectionReportsTest extends TestCase
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
        $this->manifest = tempnam(sys_get_temp_dir(), 'sb-collections-');
        config(['reporting.manifest' => $this->manifest]);
        $this->writeManifest();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->resident = User::factory()->create(['is_active' => true]);
        $this->service = Service::create(['name' => 'Barangay Clearance', 'fee_amount' => '999.00']);
        $this->schedule = Schedule::create(['date' => '2027-01-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 100]);
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

    private function payment(string $amount = '10.00', ?string $fee = 'same', string $paidAt = '2026-10-24 00:00:00', ?Service $service = null): ReservationPayment
    {
        $reservation = Reservation::create(['user_id' => $this->resident->id, 'service_id' => ($service ?? $this->service)->id,
            'schedule_id' => $this->schedule->id, 'status' => 'ready_for_pickup']);
        $reservation->forceFill(['fee_snapshot' => $fee === 'same' ? $amount : $fee, 'created_at' => '2025-01-01 00:00:00', 'admin_notes' => 'private internal note'])->save();
        $payment = new ReservationPayment;
        $payment->forceFill(['reservation_id' => $reservation->id, 'amount' => $amount, 'paid_at' => $paidAt, 'recorded_by' => $this->admin->id])->save();

        return $payment->refresh();
    }

    private function tuple(ReservationPayment $payment): array
    {
        $r = $payment->reservation;

        return ['reservation_id' => $r->id, 'user_id' => $r->user_id, 'service_id' => $r->service_id,
            'schedule_id' => $r->schedule_id, 'created_at' => $r->getRawOriginal('created_at')];
    }

    private function report(array $filters = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.reports.collections', $filters));
    }

    public function test_access_is_active_verified_admin_only(): void
    {
        $url = route('admin.reports.collections');
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->resident)->get($url)->assertForbidden();
        $this->admin->forceFill(['email_verified_at' => null])->save();
        $this->report()->assertRedirect(route('verification.notice'));
        $this->admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->report()->assertRedirect(route('login'));
        $this->admin->forceFill(['is_active' => true])->save();
        $this->report()->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Collections Reports');
    }

    public static function periods(): array
    {
        return [
            [[], '2026-09-30 16:00:00', '2026-10-31 16:00:00'],
            [['period' => 'today'], '2026-10-23 16:00:00', '2026-10-24 16:00:00'],
            [['period' => 'yesterday'], '2026-10-22 16:00:00', '2026-10-23 16:00:00'],
            [['period' => 'week'], '2026-10-18 16:00:00', '2026-10-25 16:00:00'],
            [['period' => 'date', 'date' => '2026-01-01'], '2025-12-31 16:00:00', '2026-01-01 16:00:00'],
            [['period' => 'custom', 'start' => '2026-02-28', 'end' => '2026-03-01'], '2026-02-27 16:00:00', '2026-03-01 16:00:00'],
        ];
    }

    #[DataProvider('periods')]
    public function test_payment_boundaries_and_decimal_reconciliation(array $filters, string $from, string $until): void
    {
        $first = $this->payment('0.10', paidAt: $from);
        $second = $this->payment('0.20', paidAt: CarbonImmutable::parse($until, 'UTC')->subSecond()->format('Y-m-d H:i:s'));
        $this->payment('99.00', paidAt: CarbonImmutable::parse($from, 'UTC')->subSecond()->format('Y-m-d H:i:s'));
        $this->payment('99.00', paidAt: $until);
        $data = $this->report($filters)->assertOk()->viewData('report');
        $this->assertSame('0.30', $data['amount']);
        $this->assertSame(2, $data['count']);
        $sum = BigDecimal::zero();
        foreach ($data['series'] as $row) {
            $sum = $sum->plus($row['amount']);
        }
        $this->assertTrue($sum->isEqualTo('0.30'));
        $this->assertSame('0.30', $data['services'][0]['amount']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $data['payments']->pluck('id')->all());
    }

    public static function invalidFilters(): array
    {
        return [[['period' => 'unknown']], [['period' => 'date']], [['period' => 'date', 'date' => '2026-02-30']],
            [['period' => 'custom', 'start' => '2026-10-25', 'end' => '2026-10-24']], [['period' => 'custom', 'start' => 'bad', 'end' => '2026-10-24']],
            [['view' => 'all']], [['page' => -1]], [['view' => '']]];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_hide_totals_instead_of_falling_back(array $filters): void
    {
        $this->payment();
        $this->report($filters)->assertRedirect(route('admin.reports.collections'))->assertSessionHasErrors();
        $response = $this->report()->assertSee('Please correct the filters.')->assertDontSee('Valid Recorded Collections');
        $this->assertNull($response->viewData('report'));
    }

    public static function consistencyCases(): array
    {
        return [['10.00', '10.00', true], ['9.99', '10.00', false], ['10.00', null, false], ['1.00', '0.00', false], ['-1.00', '-1.00', false]];
    }

    #[DataProvider('consistencyCases')]
    public function test_sql_consistency_matches_authoritative_status(string $amount, ?string $fee, bool $valid): void
    {
        $payment = $this->payment($amount, $fee);
        $this->assertSame($valid, app(ReservationPayments::class)->status($payment->reservation) === PaymentStatus::Paid);
        $this->assertSame($valid, ReservationPayment::consistent()->whereKey($payment->id)->exists());
        $data = $this->report()->viewData('report');
        $this->assertSame($valid ? $amount : '0.00', $data['amount']);
        $this->assertSame($valid ? 1 : 0, $data['count']);
        $this->assertSame($valid ? 0 : 1, $data['integrity_count']);
    }

    public function test_integrity_is_period_bound_and_toggle_does_not_change_valid_totals(): void
    {
        $this->payment('15.00');
        $bad = $this->payment('4.00', '5.00');
        $this->payment('7.00', '8.00', '2026-09-01 00:00:00');
        $this->report()->assertSee('1 payment integrity issue within this payment period.');
        $data = $this->report(['view' => 'integrity'])->assertSee('These recorded amounts are not included in collections.')->viewData('report');
        $this->assertSame('15.00', $data['amount']);
        $this->assertSame(1, $data['count']);
        $this->assertSame(1, $data['integrity_count']);
        $this->assertSame([$bad->id], $data['payments']->pluck('id')->all());
    }

    public function test_exclusions_cover_valid_invalid_and_old_submissions_without_inactivity_inference(): void
    {
        $fixtureService = Service::create(['name' => 'Excluded service']);
        $valid = $this->payment('10.00', service: $fixtureService);
        $invalid = $this->payment('5.00', '6.00', service: $fixtureService);
        $this->writeManifest([$this->tuple($valid), $this->tuple($invalid)]);
        $this->service->update(['name' => 'TEST legitimate inactive service', 'is_active' => false]);
        $this->resident->forceFill(['is_active' => false])->save();
        $kept = $this->payment('3.00');
        $kept->reservation->update(['status' => 'completed']);
        $data = $this->report()->assertSee('TEST legitimate inactive service')->assertDontSee('Excluded service')->viewData('report');
        $this->assertSame('3.00', $data['amount']);
        $this->assertSame(1, $data['count']);
        $this->assertSame(2, $data['excluded']);
        $this->assertSame(0, $data['integrity_count']);
        $this->assertSame('3.00', $data['services'][0]['amount']);
        $this->assertSame([$kept->id], $data['payments']->pluck('id')->all());
        $this->assertCount(0, $this->report(['view' => 'integrity'])->viewData('report')['payments']);
    }

    public static function manifestCases(): array
    {
        return array_map(fn ($case) => [$case], ['missing', 'malformed', 'empty_object', 'numeric_object', 'duplicate', 'wrong_identity', 'wrong_binding', 'unreviewed']);
    }

    #[DataProvider('manifestCases')]
    public function test_manifest_failures_block_every_component(string $case): void
    {
        $payment = $this->payment();
        $entry = $this->tuple($payment);
        $entries = [$entry];
        $overrides = [];
        if ($case === 'empty_object') {
            $overrides['excluded_reservations'] = new \stdClass;
        }
        if ($case === 'numeric_object') {
            $overrides['excluded_reservations'] = (object) [$entry];
        }
        if ($case === 'duplicate') {
            $entries[] = $entry;
        }
        if ($case === 'wrong_identity') {
            $entries[0]['created_at'] = '2025-01-01 00:00:01';
        }
        if ($case === 'wrong_binding') {
            $overrides['binding'] = [];
        }
        if ($case === 'unreviewed') {
            $overrides['reviewed'] = false;
        }
        $this->writeManifest($entries, $overrides);
        if ($case === 'missing') {
            unlink($this->manifest);
        }
        if ($case === 'malformed') {
            file_put_contents($this->manifest, '{invalid private text');
        }
        $response = $this->report()->assertOk()->assertSee('Collections reports are unavailable')->assertDontSee('Valid Recorded Collections')
            ->assertDontSee('Complete Service Breakdown')->assertDontSee($this->manifest)->assertDontSee('invalid private text');
        $this->assertNull($response->viewData('report'));
    }

    public function test_amount_ranking_other_services_and_large_exact_totals(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->payment('0.'.$i.'0', service: Service::create(['name' => 'Service '.$i]));
        }
        $data = $this->report()->viewData('report');
        $this->assertSame('2.80', $data['amount']);
        $this->assertCount(7, $data['services']);
        $this->assertCount(6, $data['ranking']);
        $this->assertSame('Service 7', $data['ranking'][0]['name']);
        $this->assertSame('Other services', $data['ranking'][5]['name']);
        $this->assertSame('0.30', $data['ranking'][5]['amount']);
        $this->assertSame(2, $data['ranking'][5]['count']);
        for ($i = 0; $i < 3; $i++) {
            $this->payment('99999999.99');
        }
        $this->assertSame('300000002.77', $this->report()->viewData('report')['amount']);
    }

    public function test_pagination_filters_stable_ties_and_navigation_are_correct(): void
    {
        $payments = [];
        for ($i = 0; $i < 17; $i++) {
            $payments[] = $this->payment('1.00');
        }
        $this->writeManifest([$this->tuple($payments[16])]);
        $response = $this->report(['period' => 'today']);
        $data = $response->viewData('report');
        $this->assertSame(16, $data['payments']->total());
        $this->assertCount(15, $data['payments']);
        $this->assertSame($payments[15]->id, $data['payments']->first()->id);
        $this->assertStringContainsString('period=today', $data['payments']->nextPageUrl());
        $this->assertStringContainsString('view=valid', $data['payments']->nextPageUrl());
        $this->assertSame($payments[0]->id, $this->report(['period' => 'today', 'page' => 2])->viewData('report')['payments']->first()->id);
        $response->assertSee(route('admin.reservations.show', $payments[0]->reservation_id), false);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Admin navigation"]//a[@aria-current="page"]')->length);
    }

    public function test_empty_zero_buckets_and_no_unpaid_or_current_price_metrics(): void
    {
        $this->report()->assertSee('No valid recorded collections in this period.')->assertDontSee('Ready for Pickup — Unpaid');
        $this->payment('0.01', paidAt: '2026-10-23 16:00:00');
        $data = $this->report(['period' => 'today'])->viewData('report');
        $this->assertCount(24, $data['series']);
        $this->assertSame('0.01', $data['series'][0]['amount']);
        $this->assertSame('0.00', $data['series'][1]['amount']);
        $this->assertSame('0.01', $data['amount']);
        foreach ([['2026-01-01', '2026-12-31', 'month'], ['2020-01-01', '2026-12-31', 'year']] as [$start,$end,$bucket]) {
            $this->assertSame($bucket, $this->report(['period' => 'custom', 'start' => $start, 'end' => $end])->viewData('report')['bucket']);
        }
    }

    public function test_read_only_escaped_private_and_bounded_queries(): void
    {
        $this->resident->update(['name' => '<script>resident()</script>']);
        $this->service->update(['name' => '<script>service()</script>']);
        for ($i = 0; $i < 18; $i++) {
            $this->payment();
        }
        $tables = ['reservations', 'reservation_payments', 'notifications', 'qr_tickets', 'reservation_status_histories', 'checkin_logs', 'reservation_documents'];
        $fingerprint = function () use ($tables) {
            $result = [];
            foreach ($tables as $table) {
                $result[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()));
            }

            return $result;
        };
        $before = $fingerprint();
        Model::preventLazyLoading();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->report()->assertOk()->assertSee('<script>resident()</script>')->assertSee('<script>service()</script>')
            ->assertDontSee('<script>resident()</script>', false)->assertDontSee('private internal note')->assertDontSee('fee_amount');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(16, count($queries));
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select /i', $query['query']);
        }
        $this->assertSame($before, $fingerprint());
    }
}
