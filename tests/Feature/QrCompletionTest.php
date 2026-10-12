<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus as Status;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\QrTicketIssuer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QrCompletionTest extends TestCase
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

    public function test_completion_consumes_existing_ticket_atomically_with_matching_admin_and_history_time(): void
    {
        $this->travelTo(now()->startOfSecond());
        $reservation = $this->reservation(Status::ReadyForPickup);
        $ticket = app(QrTicketIssuer::class)->issue($reservation)->fresh();
        $credential = array_intersect_key($ticket->getAttributes(), array_flip(['id', 'ticket_code', 'qr_payload', 'generated_at', 'created_at', 'reservation_id']));
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin)->get(route('admin.reservations.show', $reservation))->assertSee('QR Ticket')->assertSee('Active')->assertDontSee($ticket->qr_payload, false);
        $this->complete($reservation, ['used_at' => '2000-01-01', 'used_by' => $reservation->user_id, 'is_used' => false])->assertSessionHas('status');
        $ticket->refresh();
        $history = $reservation->statusHistories()->sole();
        $this->assertSame(Status::Completed, $reservation->fresh()->status);
        $this->assertTrue($ticket->isUsed());
        $this->assertTrue($ticket->used_at->equalTo(now()));
        $this->assertTrue($ticket->used_at->equalTo($history->changed_at));
        $this->assertSame($admin->id, $ticket->used_by);
        $this->assertSame($admin->id, $history->changed_by);
        $this->assertSame(Status::ReadyForPickup, $history->from_status);
        $this->assertSame(Status::Completed, $history->to_status);
        $this->assertSame($credential, array_intersect_key($ticket->getAttributes(), $credential));
        $this->assertArrayNotHasKey('used_by', $ticket->toArray());
        $this->assertArrayNotHasKey('used_at', $ticket->toArray());
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Used / Historical')->assertSee($admin->name)
            ->assertSee(now('Asia/Manila')->format('F j, Y, g:i A'))->assertDontSee($ticket->qr_payload, false);
        $this->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload()])->assertExactJson([
            'valid' => false, 'message' => 'This QR ticket has already completed its transaction.',
        ]);
        $before = $ticket->getAttributes();
        $this->travel(5)->minutes();
        $this->actingAs($this->user(UserRole::Admin));
        $this->complete($reservation)->assertSessionHasErrors('status');
        $this->assertSame($before, $ticket->fresh()->getAttributes());
        $this->assertDatabaseCount('qr_tickets', 1);
        $this->assertDatabaseCount('reservation_status_histories', 1);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public function test_approval_ready_and_repeated_verification_do_not_consume_ticket(): void
    {
        $reservation = $this->reservation(Status::UnderReview);
        $this->actingAs($this->user(UserRole::Admin))->patch(route('admin.reservations.status', $reservation), [
            'expected_status' => 'under_review', 'status' => 'approved', 'used_at' => now(), 'used_by' => 999,
        ])->assertSessionHas('status');
        $ticket = $reservation->qrTicket()->sole();
        foreach (['approved', 'ready_for_pickup'] as $status) {
            if ($status === 'ready_for_pickup') {
                $this->patch(route('admin.reservations.status', $reservation), ['expected_status' => 'approved',
                    'status' => $status, 'confirm_ready' => 1, 'notes' => 'Certificate prepared manually.'])->assertSessionHas('status');
            }
            $before = $ticket->fresh()->getAttributes();
            for ($i = 0; $i < 2; $i++) {
                $this->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload(), 'is_used' => true, 'used_by' => 999])->assertJsonPath('valid', true);
            }
            $this->assertSame($before, $ticket->fresh()->getAttributes());
            $this->assertFalse($ticket->fresh()->isUsed());
            $this->assertNull($ticket->fresh()->used_by);
        }
        $this->assertDatabaseCount('reservation_status_histories', 2);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public static function failingWrites(): array
    {
        return [['qr_tickets', 'UPDATE'], ['reservation_status_histories', 'INSERT']];
    }

    #[DataProvider('failingWrites')]
    public function test_consumption_or_history_failure_rolls_back_entire_completion(string $table, string $operation): void
    {
        $reservation = $this->reservation(Status::ReadyForPickup);
        $ticket = app(QrTicketIssuer::class)->issue($reservation);
        $original = $reservation->fresh()->getAttributes();
        $originalTicket = $ticket->fresh()->getAttributes();
        DB::statement("CREATE TRIGGER fail_completion BEFORE $operation ON $table BEGIN SELECT RAISE(ABORT, 'Simulated failure'); END");
        $this->actingAs($this->user(UserRole::Admin))->withoutExceptionHandling();
        try {
            $this->complete($reservation);
            $this->fail('Expected transaction failure');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Simulated failure', $exception->getMessage());
        }
        $this->assertSame($original, $reservation->fresh()->getAttributes());
        $this->assertSame($originalTicket, $ticket->fresh()->getAttributes());
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public function test_used_ticket_is_invalid_even_if_reservation_is_still_eligible(): void
    {
        $reservation = $this->reservation(Status::ReadyForPickup);
        $ticket = app(QrTicketIssuer::class)->issue($reservation);
        $admin = $this->user(UserRole::Admin);
        $ticket->forceFill(['used_at' => now(), 'used_by' => $admin->id])->save();
        $this->actingAs($admin)->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload()])->assertExactJson([
            'valid' => false, 'message' => 'This QR ticket has already completed its transaction.',
        ]);
    }

    public function test_guest_resident_unverified_and_inactive_admin_cannot_consume_ticket(): void
    {
        $reservation = $this->reservation(Status::ReadyForPickup);
        $ticket = app(QrTicketIssuer::class)->issue($reservation);
        $this->complete($reservation)->assertRedirect(route('login'));
        $this->actingAs($reservation->user);
        $this->complete($reservation)->assertForbidden();
        $admin = $this->user(UserRole::Admin);
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin);
        $this->complete($reservation)->assertRedirect(route('verification.notice'));
        $admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($admin);
        $this->complete($reservation)->assertRedirect(route('login'));
        $this->assertFalse($ticket->fresh()->isUsed());
        $this->assertSame(Status::ReadyForPickup, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public function test_forward_migration_preserves_existing_ticket_and_legacy_completion_remains_unusable(): void
    {
        $migration = require database_path('migrations/2026_10_12_000014_add_usage_to_qr_tickets.php');
        $migration->down();
        $reservation = $this->reservation(Status::ReadyForPickup);
        $ticket = app(QrTicketIssuer::class)->issue($reservation);
        $before = $ticket->fresh()->getAttributes();
        $migration->up();
        $this->assertSame($before, array_intersect_key($ticket->fresh()->getAttributes(), $before));
        $this->assertNull($ticket->fresh()->used_at);
        $this->assertNull($ticket->fresh()->used_by);
        $reservation->update(['status' => Status::Completed]);
        $this->actingAs($this->user(UserRole::Admin))->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload()])->assertJsonPath('valid', false);
        $this->get(route('admin.reservations.show', $reservation))->assertSee('Used / Historical')->assertSee('not recorded for this earlier completion');
    }

    public function test_legacy_reservation_without_ticket_can_complete_without_generating_one(): void
    {
        $reservation = $this->reservation(Status::ReadyForPickup);
        $this->actingAs($this->user(UserRole::Admin));
        $this->complete($reservation)->assertSessionHas('status');
        $this->assertSame(Status::Completed, $reservation->fresh()->status);
        $this->assertDatabaseCount('qr_tickets', 0);
        $this->assertDatabaseCount('reservation_status_histories', 1);
    }

    public function test_direct_completion_from_approved_is_rejected_without_consumption(): void
    {
        $reservation = $this->reservation(Status::Approved);
        $ticket = app(QrTicketIssuer::class)->issue($reservation);
        $this->actingAs($this->user(UserRole::Admin));
        $this->complete($reservation, ['expected_status' => 'approved'])->assertSessionHasErrors('status');
        $this->assertFalse($ticket->fresh()->isUsed());
        $this->assertSame(Status::Approved, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public function test_completion_retains_csrf_protection(): void
    {
        $reservation = $this->reservation(Status::ReadyForPickup);
        $ticket = app(QrTicketIssuer::class)->issue($reservation);
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->actingAs($this->user(UserRole::Admin));
        $this->complete($reservation)->assertStatus(419);
        $this->assertFalse($ticket->fresh()->isUsed());
        $this->assertSame(Status::ReadyForPickup, $reservation->fresh()->status);
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    private function complete(Reservation $reservation, array $extra = [])
    {
        return $this->patch(route('admin.reservations.status', $reservation), [
            'expected_status' => 'ready_for_pickup', 'status' => 'completed', ...$extra,
        ]);
    }

    private function user(UserRole $role = UserRole::Resident): User
    {
        $user = User::factory()->make();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }

    private function reservation(Status $status): Reservation
    {
        $service = Service::create(['name' => 'Barangay Clearance']);
        $schedule = Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5]);

        return $this->user()->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);
    }
}
