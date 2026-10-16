<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\QrTicketIssuer;
use App\Services\ReservationActivityTimeline;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CertificatePdf;
use Tests\TestCase;

class ReservationActivityTimelineTest extends TestCase
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

    private function fixture(bool $full = false, ?string $fee = '50.00'): Reservation
    {
        $admin = User::factory()->create(['name' => 'Authoritative Admin', 'role' => UserRole::Admin, 'is_active' => true]);
        $owner = User::factory()->create(['name' => 'Current Resident', 'role' => UserRole::Resident, 'is_active' => true]);
        $service = Service::create(['name' => 'Official Service', 'fee_amount' => '999.00']);
        $schedule = Schedule::create(['date' => '2026-12-23', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10]);
        $r = $owner->reservations()->make(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $full ? 'completed' : 'pending']);
        $r->forceFill(['fee_snapshot' => $fee, 'created_at' => '2026-10-16 00:00:00', 'resident_snapshot' => ['resident_name' => 'Historical Resident']])->save();
        $this->actingAs($admin);
        if ($full) {
            $previous = 'pending';
            foreach (['under_review' => '01', 'approved' => '02', 'ready_for_pickup' => '03', 'completed' => '06'] as $status => $hour) {
                $r->statusHistories()->create(['from_status' => $previous, 'to_status' => $status, 'changed_by' => $admin->id,
                    'changed_at' => '2026-10-16 '.$hour.':00:00', 'notes' => 'Internal note <script>alert("x")</script>']);
                $previous = $status;
            }
            $r->qrTicket()->create(['ticket_code' => 'a77c04cb-8754-4d0f-a0a0-c993195333fd', 'qr_payload' => 'a77c04cb-8754-4d0f-a0a0-c993195333fd', 'generated_at' => '2026-10-16 02:00:00'])
                ->forceFill(['used_at' => '2026-10-16 06:00:00', 'used_by' => $admin->id])->save();
            $bytes = CertificatePdf::bytes();
            $path = 'reservation-documents/'.$r->id.'/'.str_repeat('a', 40).'.pdf';
            Storage::disk('local')->put($path, $bytes);
            $r->document()->create(['template_revision' => str_repeat('a', 64), 'template_filename' => 'Official.pdf', 'original_filename' => 'Personalized.pdf',
                'stored_path' => $path, 'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'content_hash' => hash('sha256', $bytes),
                'generated_by' => $admin->id, 'generated_at' => '2026-10-16 02:30:00']);
            $r->checkinLogs()->create(['verification_result' => 'valid', 'verified_at' => '2026-10-16 03:30:00', 'verified_by' => $admin->id, 'scanned_ticket_code' => 'PRIVATE SCANNED CREDENTIAL']);
            $r->checkinLogs()->create(['verification_result' => 'checked_in', 'verified_at' => '2026-10-16 04:00:00', 'verified_by' => $admin->id]);
            (new ReservationPayment)->forceFill(['reservation_id' => $r->id, 'amount' => '49.99', 'paid_at' => '2026-10-16 05:00:00', 'recorded_by' => $admin->id])->save();
        }

        return $r;
    }

    public function test_detail_uses_authoritative_sources_times_actors_and_one_completion(): void
    {
        $r = $this->fixture(true);
        $response = $this->get(route('admin.reservations.show', $r))->assertOk()->assertSee('Reservation Activity');
        $events = $response->viewData('activities');
        $this->assertSame(['submitted', 'status_changed', 'status_changed', 'qr_issued', 'document_finalized', 'status_changed', 'checked_in', 'payment_recorded', 'status_changed'], $events->pluck('type')->all());
        $this->assertSame(['08:00:00', '09:00:00', '10:00:00', '10:00:00', '10:30:00', '11:00:00', '12:00:00', '13:00:00', '14:00:00'], $events->map(fn ($e) => $e['timestamp']->format('H:i:s'))->all());
        $this->assertSame('Historical Resident', $events->first()['actor']);
        $this->assertSame('System', $events->firstWhere('type', 'qr_issued')['actor']);
        $this->assertSame('Authoritative Admin', $events->firstWhere('type', 'checked_in')['actor']);
        $this->assertSame('On-site payment recorded: ₱49.99.', $events->firstWhere('type', 'payment_recorded')['description']);
        $this->assertSame(1, $events->where('title', 'Completed')->count());
        $this->assertTrue($events->last()['qr_used']);
        $this->assertSame(['table' => 'reservation_status_histories', 'id' => $r->statusHistories()->where('to_status', 'completed')->sole()->id], $events->last()['source']);
        $response->assertSee('QR marked Used')->assertSee('Internal note &lt;script&gt;', false)->assertDontSee('<script>alert("x")</script>', false);
    }

    public function test_same_time_workflow_ties_are_deterministic_in_both_feeds(): void
    {
        $r = $this->fixture(true);
        foreach (['reservations' => 'created_at', 'reservation_status_histories' => 'changed_at', 'qr_tickets' => 'generated_at', 'reservation_documents' => 'generated_at', 'checkin_logs' => 'verified_at', 'reservation_payments' => 'paid_at'] as $table => $field) {
            DB::table($table)->update([$field => '2026-10-16 02:00:00']);
        }
        DB::table('qr_tickets')->update(['used_at' => '2026-10-16 02:00:00']);
        $timeline = app(ReservationActivityTimeline::class);
        $forward = $timeline->forReservation($r->id);
        $this->assertSame(['Request Submitted', 'Under Review', 'Approved', 'QR Issued', 'Certificate Finalized', 'Ready for Pickup', 'Checked In', 'Payment Recorded', 'Completed'], $forward->pluck('title')->all());
        $this->assertSame($forward->pluck('source')->all(), $timeline->forReservation($r->id)->pluck('source')->all());
        $this->assertSame($forward->reverse()->values()->pluck('source')->all(), collect($timeline->paginate()->items())->pluck('source')->all());
    }

    public static function legacyFees(): array
    {
        return [['0.00'], [null], ['50.00']];
    }

    #[DataProvider('legacyFees')]
    public function test_partial_history_never_fabricates_payment_arrival_qr_or_certificate(?string $fee): void
    {
        $r = $this->fixture(false, $fee);
        $response = $this->get(route('admin.reservations.show', $r))->assertOk()->assertSee('Request Submitted');
        $this->assertSame(['submitted'], $response->viewData('activities')->pluck('type')->all());
        $logs = $this->get(route('admin.reservation-logs.index'))->assertOk();
        $this->assertSame(['submitted'], collect($logs->viewData('activities')->items())->pluck('type')->all());
    }

    public function test_repeated_production_qr_verification_never_creates_arrival_activity(): void
    {
        $r = $this->fixture();
        $r->update(['status' => 'ready_for_pickup']);
        $ticket = app(QrTicketIssuer::class)->issue($r)->fresh();
        $before = $ticket->getAttributes();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload()])->assertJsonPath('valid', true);
        }
        $this->assertSame(['submitted', 'qr_issued'], app(ReservationActivityTimeline::class)->forReservation($r->id)->pluck('type')->all());
        $this->assertSame($before, $ticket->fresh()->getAttributes());
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public function test_stored_historical_qr_time_is_not_reconstructed_and_missing_actor_is_safe(): void
    {
        $r = $this->fixture();
        $former = User::factory()->create(['name' => 'Deleted Audit Actor']);
        $r->statusHistories()->create(['to_status' => 'approved', 'changed_by' => $former->id, 'changed_at' => '2026-10-16 01:00:00']);
        $r->qrTicket()->create(['ticket_code' => 'legacy-credential', 'qr_payload' => 'legacy-credential', 'generated_at' => '2026-10-17 04:05:06']);
        $former->delete();
        $events = $this->get(route('admin.reservations.show', $r))->assertOk()->assertSee('Former account')->viewData('activities');
        $this->assertSame('2026-10-17 12:05:06', $events->firstWhere('type', 'qr_issued')['timestamp']->format('Y-m-d H:i:s'));
        $this->assertSame('System', $events->firstWhere('type', 'qr_issued')['actor']);
    }

    public static function usageAnomalies(): array
    {
        return [['no_history'], ['time'], ['actor'], ['unknown_actor']];
    }

    #[DataProvider('usageAnomalies')]
    public function test_usage_anomaly_is_reported_without_invented_completion(string $kind): void
    {
        $r = $this->fixture(true);
        if ($kind === 'no_history') {
            $r->statusHistories()->where('to_status', 'completed')->delete();
        } elseif ($kind === 'time') {
            $r->qrTicket->forceFill(['used_at' => '2026-10-16 06:01:00'])->save();
        } elseif ($kind === 'actor') {
            $r->qrTicket->forceFill(['used_by' => $r->user_id])->save();
        } else {
            DB::table('qr_tickets')->update(['used_by' => null]);
            $r->statusHistories()->where('to_status', 'completed')->update(['changed_by' => null]);
        }
        $events = $this->get(route('admin.reservations.show', $r))->assertOk()->assertSee('QR usage does not match')->viewData('activities');
        $this->assertFalse($events->contains(fn ($e) => $e['qr_used']));
        $this->assertSame($kind === 'no_history' ? 0 : 1, $events->where('title', 'Completed')->count());
    }

    public static function blockedAccounts(): array
    {
        return [['guest'], ['resident'], ['inactive'], ['unverified']];
    }

    #[DataProvider('blockedAccounts')]
    public function test_log_access_uses_existing_admin_policy(string $kind): void
    {
        if ($kind === 'guest') {
            $this->get(route('admin.reservation-logs.index'))->assertRedirect(route('login'));
        } else {
            $user = User::factory()->create(['role' => $kind === 'resident' ? UserRole::Resident : UserRole::Admin,
                'is_active' => $kind !== 'inactive', 'email_verified_at' => $kind === 'unverified' ? null : now()]);
            $response = $this->actingAs($user)->get(route('admin.reservation-logs.index'));
            match ($kind) {
                'resident' => $response->assertForbidden(),
                'inactive' => $response->assertRedirect(route('login')),
                'unverified' => $response->assertRedirect(route('verification.notice')),
            };
        }
    }

    public function test_outputs_are_private_escaped_read_only_and_resident_tracking_is_unchanged(): void
    {
        $r = $this->fixture(true);
        $before = $this->fingerprint();
        foreach ([route('admin.reservations.show', $r), route('admin.reservation-logs.index')] as $url) {
            $this->get($url)->assertOk()->assertDontSee($r->qrTicket->ticket_code, false)->assertDontSee($r->qrTicket->encodedPayload(), false)
                ->assertDontSee($r->document->stored_path, false)->assertDontSee('PRIVATE SCANNED CREDENTIAL')->assertDontSee('Edit log')->assertDontSee('Delete log');
        }
        $this->assertSame($before, $this->fingerprint());
        $this->actingAs($r->user)->get(route('resident.request-status.show', $r))->assertOk()->assertDontSee('Authoritative Admin')->assertDontSee('Internal note')->assertDontSee('QR usage does not match');
        $this->post(route('admin.reservation-logs.index'))->assertStatus(405);
    }

    public function test_global_search_type_filter_links_and_validation(): void
    {
        $r = $this->fixture(true);
        foreach ([(string) $r->id, '#'.$r->id, 'Request #'.$r->id, 'Current Resident', 'Historical Resident', 'Official Service'] as $search) {
            $response = $this->get(route('admin.reservation-logs.index', ['search' => $search, 'type' => 'payment_recorded']))
                ->assertOk()->assertSee('Payment Recorded')->assertSee(route('admin.reservations.show', $r), false);
            $this->assertSame(1, $response->viewData('activities')->total());
            $this->assertSame($r->id, $response->viewData('activities')->items()[0]['reservation_id']);
        }
        $this->get(route('admin.reservation-logs.index', ['search' => "' OR 1=1 --"]))->assertOk()->assertSee('No matching activities');
        $this->get(route('admin.reservation-logs.index', ['type' => 'qr_used']))->assertSessionHasErrors('type');
        $this->get(route('admin.reservation-logs.index', ['search' => str_repeat('a', 256)]))->assertSessionHasErrors('search');
    }

    public function test_database_pagination_preserves_same_time_rows_and_filters_without_n_plus_one(): void
    {
        $r = $this->fixture();
        for ($i = 0; $i < 21; $i++) {
            $copy = $r->replicate();
            $copy->created_at = $r->created_at;
            $copy->save();
        }
        DB::enableQueryLog();
        $page1 = app(ReservationActivityTimeline::class)->paginate(['search' => 'Historical Resident', 'type' => 'submitted']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(22, $page1->total());
        $this->assertCount(15, $page1->items());
        $this->assertStringContainsString('type=submitted', $page1->nextPageUrl());
        $this->assertStringContainsString('Historical%20Resident', $page1->nextPageUrl());
        $this->assertTrue(collect($queries)->contains(fn ($q) => str_contains(strtolower($q['query']), 'limit 15')));
        $this->assertFalse(collect($queries)->contains(fn ($q) => preg_match('/select .* from ["`]users["`] where/i', $q['query'])));
        $this->get(route('admin.reservation-logs.index', ['page' => 2, 'search' => 'Historical Resident', 'type' => 'submitted']))->assertOk()
            ->assertViewHas('activities', function ($page2) use ($page1) {
                $ids = collect([...$page1->items(), ...$page2->items()])->pluck('reservation_id');
                $this->assertSame(22, $ids->unique()->count());
                $this->assertSame(range(22, 1), $ids->all());

                return true;
            });
    }

    public function test_single_timeline_scopes_all_sources_and_joins_actors_without_growth_queries(): void
    {
        $r = $this->fixture(true);
        $other = $r->replicate();
        $other->save();
        for ($i = 0; $i < 30; $i++) {
            $r->statusHistories()->create(['to_status' => 'under_review', 'changed_at' => '2026-10-16 01:00:00', 'changed_by' => $r->user_id]);
        }
        DB::enableQueryLog();
        $events = app(ReservationActivityTimeline::class)->forReservation($r->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(39, $events);
        $this->assertSame([$r->id], $events->pluck('reservation_id')->unique()->values()->all());
        $this->assertTrue(collect($queries)->contains(fn ($q) => str_contains(strtolower($q['query']), 'union all')));
        $this->assertFalse(collect($queries)->contains(fn ($q) => preg_match('/select .* from ["`]users["`] where/i', $q['query'])));
    }

    public function test_absent_time_and_malformed_optional_snapshot_have_safe_read_only_fallbacks(): void
    {
        $r = $this->fixture();
        DB::table('reservations')->where('id', $r->id)->update(['created_at' => null, 'resident_snapshot' => '{invalid']);
        $this->assertCount(0, app(ReservationActivityTimeline::class)->forReservation($r->id));
        $this->get(route('admin.reservation-logs.index'))->assertOk()->assertSee('No matching activities');
        $r->statusHistories()->create(['to_status' => 'approved', 'changed_at' => '2026-10-16 02:00:00']);
        $event = app(ReservationActivityTimeline::class)->forReservation($r->id)->sole();
        $this->assertSame('Current Resident', $event['resident']);
        $this->assertSame('Former account', $event['actor']);
    }

    private function fingerprint(): array
    {
        return collect(['reservations', 'reservation_status_histories', 'qr_tickets', 'reservation_documents', 'checkin_logs', 'reservation_payments'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
