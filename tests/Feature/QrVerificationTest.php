<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus as Status;
use App\Enums\UserRole;
use App\Models\QrTicket;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\QrTicketIssuer;
use App\Services\QrTicketVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QrVerificationTest extends TestCase
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

    public function test_page_and_endpoint_require_active_verified_admin(): void
    {
        $this->get('/admin/qr-verification')->assertRedirect(route('login'));
        $this->postJson('/admin/qr-verification')->assertUnauthorized();
        $this->actingAs($this->user())->get('/admin/qr-verification')->assertForbidden();
        $this->postJson('/admin/qr-verification')->assertForbidden();
        $admin = $this->user(UserRole::Admin);
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->get('/admin/qr-verification')->assertRedirect(route('verification.notice'));
        $this->postJson('/admin/qr-verification')->assertForbidden();
        $admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($admin)->get('/admin/qr-verification')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($admin)->postJson('/admin/qr-verification')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($this->user(UserRole::Admin))->get('/admin/qr-verification')->assertOk()
            ->assertSee('Start Camera')->assertSee('Verification only')->assertSee('aria-current="page"', false);
    }

    public static function eligibleStatuses(): array
    {
        return [[Status::Approved], [Status::ReadyForPickup]];
    }

    #[DataProvider('eligibleStatuses')]
    public function test_valid_ticket_returns_only_safe_details_and_never_writes(Status $status): void
    {
        $ticket = $this->ticket($status);
        $resident = $ticket->reservation->user;
        $before = $this->snapshot();
        $this->actingAs($this->user(UserRole::Admin));
        for ($i = 0; $i < 2; $i++) {
            $response = $this->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload(),
                'reservation_id' => 999, 'user_id' => 999, 'status' => 'completed', 'valid' => true]);
            $response->assertOk()->assertExactJson(['valid' => true, 'message' => 'Valid QR Ticket', 'reservation' => [
                'resident' => $resident->name, 'service' => 'Barangay Clearance',
                'schedule' => 'November 1, 2026 · 09:00–10:00', 'status' => $status->label(),
            ]])->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            foreach ([$resident->email, $resident->password, $resident->address, $ticket->qr_payload] as $private) {
                $this->assertStringNotContainsString($private, $response->getContent());
            }
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $this->get('/admin/qr-verification')->assertDontSee($ticket->qr_payload, false)->assertDontSee($resident->name);
    }

    public static function invalidInputs(): array
    {
        return [[null], [''], [123], [[]], ['SBQ1:1'], ['https://example.com'], ['SBQ1:'.str_repeat('a', 10000)],
            ['SBQ1:12345678-1234-1234-1234-123456789012'], ['SBQ1:00000000-0000-4000-8000-000000000000'],
            ["SBQ1:' OR 1=1 --"], ['<script>alert(1)</script>']];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_and_random_credentials_reveal_no_resident_data(mixed $payload): void
    {
        $this->ticket(Status::Approved);
        $this->actingAs($this->user(UserRole::Admin))->postJson('/admin/qr-verification', ['payload' => $payload])
            ->assertOk()->assertExactJson(['valid' => false, 'message' => 'Unknown or invalid QR ticket.']);
        $this->assertDatabaseCount('qr_tickets', 1);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public static function ineligibleStatuses(): array
    {
        return [[Status::Pending], [Status::UnderReview], [Status::Cancelled], [Status::Rejected], [Status::Completed]];
    }

    #[DataProvider('ineligibleStatuses')]
    public function test_current_database_status_controls_validity_and_hides_details(Status $status): void
    {
        $ticket = $this->ticket(Status::Approved);
        $ticket->reservation->update(['status' => $status]);
        $before = $this->snapshot();
        $this->actingAs($this->user(UserRole::Admin))->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload()])
            ->assertOk()->assertExactJson(['valid' => false, 'message' => 'This reservation is not eligible for QR verification.']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_foreign_ticket_does_not_expose_details_to_resident_or_override_owner(): void
    {
        $ticket = $this->ticket(Status::Approved);
        $other = $this->user();
        $this->actingAs($other)->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload()])
            ->assertForbidden()->assertDontSee($ticket->reservation->user->name);
        // Admin verification intentionally accepts presented tickets from any Resident.
        $this->actingAs($this->user(UserRole::Admin))->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload(), 'user_id' => $other->id])
            ->assertJsonPath('reservation.resident', $ticket->reservation->user->name);
    }

    public function test_corrupt_ticket_and_missing_reservation_are_invalid(): void
    {
        $ticket = $this->ticket(Status::Approved);
        $ticket->update(['ticket_code' => (string) Str::uuid()]);
        $this->actingAs($this->user(UserRole::Admin));
        $this->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload()])->assertJsonPath('valid', false)->assertJsonMissingPath('reservation');
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('qr_tickets')->where('id', $ticket->id)->update(['reservation_id' => 999]);
        DB::statement('PRAGMA foreign_keys = ON');
        $this->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload()])->assertJsonPath('valid', false)->assertJsonMissingPath('reservation');
    }

    public function test_inactive_or_unverified_ticket_owner_is_invalid(): void
    {
        $ticket = $this->ticket(Status::Approved);
        $this->actingAs($this->user(UserRole::Admin));
        foreach ([['is_active' => false], ['is_active' => true, 'email_verified_at' => null]] as $attributes) {
            $ticket->reservation->user->forceFill($attributes)->save();
            $this->postJson('/admin/qr-verification', ['payload' => $ticket->encodedPayload()])->assertJsonPath('valid', false)->assertJsonMissingPath('reservation');
        }
    }

    public function test_csrf_is_enforced_and_rate_limit_bounds_repeated_requests(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->actingAs($this->user(UserRole::Admin))->postJson('/admin/qr-verification', ['payload' => 'invalid'])->assertStatus(419);
        $this->withSession(['_token' => 'test-csrf-token']);
        for ($i = 0; $i < 30; $i++) {
            $this->withHeader('X-CSRF-TOKEN', 'test-csrf-token')->postJson('/admin/qr-verification', ['payload' => 'invalid'])->assertOk();
        }
        $this->postJson('/admin/qr-verification', ['payload' => 'invalid'])->assertStatus(429);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    private function snapshot(): array
    {
        return collect(['reservations', 'qr_tickets', 'services', 'schedules', 'reservation_status_histories', 'checkin_logs'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_database_failure_returns_safe_unavailable_response_without_credentials(): void
    {
        $this->mock(QrTicketVerifier::class)->shouldReceive('verify')->once()->andThrow(
            new QueryException('sqlite', 'private query', ['private credential'], new \Exception('Database details'))
        );
        $this->actingAs($this->user(UserRole::Admin))->postJson('/admin/qr-verification', ['payload' => 'private credential'])
            ->assertStatus(503)->assertExactJson(['message' => 'Verification is unavailable. Please try again.']);
    }

    private function user(UserRole $role = UserRole::Resident): User
    {
        $user = User::factory()->make(['address' => 'Private Calayo Address']);
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }

    private function ticket(Status $status): QrTicket
    {
        $service = Service::create(['name' => 'Barangay Clearance']);
        $schedule = Schedule::create(['date' => '2026-11-01', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'capacity' => 5]);
        $reservation = $this->user()->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => $status]);

        return app(QrTicketIssuer::class)->issue($reservation);
    }
}
