<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus as Status;
use App\Enums\UserRole;
use App\Models\QrTicket;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\QrTicketIssuer;
use App\Services\QrTicketRenderer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QrTicketsTest extends TestCase
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

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public static function endpoints(): array
    {
        return [['/resident/qr-tickets'], ['/resident/qr-tickets/1'], ['/resident/qr-tickets/1/image']];
    }

    #[DataProvider('endpoints')]
    public function test_routes_require_active_verified_resident(string $path): void
    {
        $resident = $this->user();
        $this->ticket($this->reservation($resident, Status::Approved));
        $this->get($path)->assertRedirect(route('login'));
        $this->actingAs($this->user(UserRole::Admin))->get($path)->assertForbidden();
        $resident->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($resident)->get($path)->assertRedirect(route('verification.notice'));
        $resident->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($resident)->get($path)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_only_owned_tickets_are_listed_and_foreign_images_never_reach_renderer(): void
    {
        $resident = $this->user();
        $own = $this->ticket($this->reservation($resident, Status::Approved, 'Owned Clearance'));
        $foreign = $this->ticket($this->reservation($this->user(), Status::Approved, 'Private Foreign Document'));
        $this->actingAs($resident)->get('/resident/qr-tickets')->assertOk()->assertSee('Owned Clearance')->assertDontSee('Private Foreign Document');
        $this->get(route('resident.qr-tickets.show', $own))->assertOk()->assertSee('Owned Clearance');
        $this->mock(QrTicketRenderer::class)->shouldNotReceive('render');
        foreach ([$foreign->id, 999] as $id) {
            $this->get('/resident/qr-tickets/'.$id)->assertNotFound();
            $this->get('/resident/qr-tickets/'.$id.'/image')->assertNotFound();
        }
    }

    public static function ineligibleStatuses(): array
    {
        return [[Status::Pending], [Status::UnderReview], [Status::Rejected], [Status::Cancelled], [Status::Completed]];
    }

    #[DataProvider('ineligibleStatuses')]
    public function test_ineligible_reservations_never_receive_a_ticket(Status $status): void
    {
        $reservation = $this->reservation($this->user(), $status);
        $this->assertNull(app(QrTicketIssuer::class)->issue($reservation));
        $this->assertDatabaseCount('qr_tickets', 0);
        $this->assertSame($status, $reservation->fresh()->status);
    }

    public function test_approval_issues_one_ticket_and_replay_refresh_or_reissue_preserves_it(): void
    {
        $reservation = $this->reservation($this->user(), Status::UnderReview);
        $unchanged = $reservation->only(['user_id', 'service_id', 'schedule_id', 'purpose']);
        $serviceData = $reservation->service->getAttributes();
        $scheduleData = $reservation->schedule->getAttributes();
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->patch(route('admin.reservations.status', $reservation), $this->approval())->assertSessionHas('status');
        $ticket = $reservation->qrTicket()->sole();
        $this->assertSame(Status::Approved, $reservation->fresh()->status);
        $history = $reservation->statusHistories()->sole();
        $this->assertSame(Status::UnderReview, $history->from_status);
        $this->assertSame(Status::Approved, $history->to_status);
        $this->assertSame($admin->id, $history->changed_by);
        $this->patch(route('admin.reservations.status', $reservation), $this->approval())->assertSessionHasErrors('status');
        $this->assertSame($ticket->id, app(QrTicketIssuer::class)->issue($reservation)->id);
        $this->get(route('admin.reservations.show', $reservation))->assertSee('QR ticket issued')->assertDontSee($ticket->qr_payload, false);
        $this->actingAs($reservation->user);
        for ($i = 0; $i < 2; $i++) {
            $this->get(route('resident.qr-tickets.show', $ticket))->assertOk();
            $this->get(route('resident.qr-tickets.image', $ticket))->assertOk();
        }
        $this->assertSame($unchanged, $reservation->fresh()->only(array_keys($unchanged)));
        $this->assertSame($serviceData, $reservation->service->fresh()->getAttributes());
        $this->assertSame($scheduleData, $reservation->schedule->fresh()->getAttributes());
        $this->assertSame($ticket->getAttributes(), $ticket->fresh()->getAttributes());
        $this->assertDatabaseCount('qr_tickets', 1);
        $this->assertDatabaseCount('reservation_status_histories', 1);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public function test_credentials_are_unique_random_uuid_v4_without_personal_data_or_plaintext_exposure(): void
    {
        $resident = $this->user();
        $resident->update(['name' => 'Private Resident Name', 'contact_number' => '09171234567', 'address' => 'Private Calayo Address']);
        $first = $this->ticket($this->reservation($resident, Status::Approved));
        $second = $this->ticket($this->reservation($resident, Status::Approved));
        $this->assertNotSame($first->qr_payload, $second->qr_payload);
        $this->assertMatchesRegularExpression('/^SBQ1:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first->encodedPayload());
        foreach ([$resident->name, $resident->email, $resident->contact_number, $resident->address, $resident->password] as $value) {
            $this->assertStringNotContainsString($value, $first->encodedPayload());
        }
        $this->assertArrayNotHasKey('qr_payload', $first->toArray());
        $this->assertArrayNotHasKey('ticket_code', $first->toArray());
        $this->actingAs($resident)->get(route('resident.qr-tickets.show', $first))->assertOk()
            ->assertDontSee($first->qr_payload, false)->assertDontSee($first->ticket_code, false)->assertSee('Wait for the Ready for Pickup');
        $this->get('/resident/qr-tickets')->assertDontSee($first->qr_payload, false);
        $image = $this->get(route('resident.qr-tickets.image', $first))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $image->getContent());
        $this->assertStringContainsString('translate(4,4)', $image->getContent());
        $this->assertStringNotContainsString($first->qr_payload, $image->getContent());
        $this->assertStringContainsString('no-store', $image->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $image->headers->get('Cache-Control'));
    }

    public static function failingWrites(): array
    {
        return [['qr_tickets'], ['reservation_status_histories']];
    }

    #[DataProvider('failingWrites')]
    public function test_ticket_or_history_failure_rolls_back_entire_approval(string $table): void
    {
        $reservation = $this->reservation($this->user(), Status::UnderReview);
        $original = $reservation->fresh()->getAttributes();
        DB::statement("CREATE TRIGGER fail_write BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT, 'Simulated write failure'); END");
        $this->actingAs($this->user(UserRole::Admin))->withoutExceptionHandling();
        try {
            $this->patch(route('admin.reservations.status', $reservation), $this->approval());
            $this->fail('Expected write failure');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Simulated write failure', $exception->getMessage());
        }
        $this->assertSame($original, $reservation->fresh()->getAttributes());
        $this->assertDatabaseCount('qr_tickets', 0);
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public function test_ready_and_completed_keep_original_ticket_with_clear_historical_display(): void
    {
        $resident = $this->user();
        $reservation = $this->reservation($resident, Status::Approved);
        $ticket = $this->ticket($reservation)->fresh();
        $this->actingAs($this->user(UserRole::Admin));
        foreach ([[Status::Approved, Status::ReadyForPickup], [Status::ReadyForPickup, Status::Completed]] as [$from, $to]) {
            $this->patch(route('admin.reservations.status', $reservation), ['expected_status' => $from->value, 'status' => $to->value])->assertSessionHas('status');
            $this->assertSame($ticket->getAttributes(), $ticket->fresh()->getAttributes());
        }
        $this->actingAs($resident)->get(route('resident.qr-tickets.show', $ticket))->assertOk()
            ->assertSee('Historical ticket')->assertSee('not an active pickup ticket')->assertSee('Sunday, November 1, 2026')->assertSee('9:00 AM');
        $this->get(route('resident.qr-tickets.image', $ticket))->assertOk();
        $this->assertDatabaseCount('qr_tickets', 1);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public function test_disallowed_legacy_tickets_do_not_display_usable_qr_graphics(): void
    {
        $resident = $this->user();
        foreach ([Status::Pending, Status::UnderReview, Status::Rejected, Status::Cancelled] as $status) {
            $reservation = $this->reservation($resident, $status);
            $credential = (string) Str::uuid();
            $ticket = $reservation->qrTicket()->create(['ticket_code' => $credential, 'qr_payload' => $credential, 'generated_at' => now()]);
            $this->actingAs($resident)->get(route('resident.qr-tickets.show', $ticket))->assertOk()
                ->assertSee('QR ticket unavailable')->assertDontSee(route('resident.qr-tickets.image', $ticket), false);
            $this->get(route('resident.qr-tickets.image', $ticket))->assertNotFound();
            $this->assertNull(app(QrTicketIssuer::class)->issue($reservation));
        }
        $this->assertDatabaseCount('qr_tickets', 4);
    }

    public function test_backfill_is_explicit_dry_runnable_idempotent_and_preserves_history(): void
    {
        $resident = $this->user();
        foreach (Status::cases() as $status) {
            $this->reservation($resident, $status);
        }
        $this->actingAs($resident)->get('/resident/qr-tickets')->assertSee('No QR tickets yet');
        $this->get('/resident/home')->assertOk();
        $this->assertDatabaseCount('qr_tickets', 0);
        $this->artisan('smartbarangay:backfill-qr-tickets', ['--dry-run' => true])
            ->expectsOutput('Dry run: 2 eligible reservation(s) without a QR ticket. No records changed.')->assertSuccessful();
        $this->assertDatabaseCount('qr_tickets', 0);
        $before = Reservation::all()->map->getAttributes()->all();
        $this->artisan('smartbarangay:backfill-qr-tickets')->expectsOutput('Issued 2 missing QR ticket(s). Existing tickets and reservation history were preserved.')->assertSuccessful();
        $tickets = QrTicket::all()->map->getAttributes()->all();
        $this->artisan('smartbarangay:backfill-qr-tickets')->expectsOutput('Issued 0 missing QR ticket(s). Existing tickets and reservation history were preserved.')->assertSuccessful();
        $this->assertSame($before, Reservation::all()->map->getAttributes()->all());
        $this->assertSame($tickets, QrTicket::all()->map->getAttributes()->all());
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public function test_issuer_rechecks_database_status_instead_of_stale_model(): void
    {
        $reservation = $this->reservation($this->user(), Status::Approved);
        Reservation::whereKey($reservation->id)->update(['status' => Status::Cancelled]);
        $this->assertNull(app(QrTicketIssuer::class)->issue($reservation));
        $this->assertDatabaseCount('qr_tickets', 0);
    }

    public function test_empty_state_pagination_and_navigation(): void
    {
        $resident = $this->user();
        $this->actingAs($resident)->get('/resident/qr-tickets')->assertOk()->assertSee('No QR tickets yet');
        for ($i = 1; $i <= 11; $i++) {
            $this->ticket($this->reservation($resident, Status::Approved, sprintf('Document %02d', $i)));
        }
        $this->get('/resident/qr-tickets')->assertSee('Showing 1–10 of 11 tickets')->assertSee('Document 11')->assertDontSee('Document 01')
            ->assertSee('aria-current="page"', false);
        $this->get('/resident/qr-tickets?page=2')->assertSee('Document 01')->assertDontSee('Document 11');
        $this->get('/resident/qr-tickets?page=99')->assertSee('No tickets on this page');
    }

    private function approval(): array
    {
        return ['expected_status' => 'under_review', 'status' => 'approved'];
    }

    private function ticket(Reservation $reservation): QrTicket
    {
        return app(QrTicketIssuer::class)->issue($reservation);
    }

    private function user(UserRole $role = UserRole::Resident): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }

    private function reservation(User $resident, Status $status, string $name = 'Barangay Clearance'): Reservation
    {
        $service = Service::create(['name' => $name]);
        $schedule = Schedule::first() ?? Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5]);

        return $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
    }
}
