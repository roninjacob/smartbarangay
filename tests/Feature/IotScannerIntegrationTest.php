<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\IotDevice;
use App\Models\IotScanEvent;
use App\Models\IotScannerPairing;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CheckInReservations;
use App\Services\IotScannerSessions;
use App\Services\QrTicketIssuer;
use App\Services\QrTicketVerifier;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class IotScannerIntegrationTest extends TestCase
{
    private IotDevice $device;

    private User $admin;

    private Request $browser;

    private IotScannerSessions $scanners;

    private string $challenge;

    private string $pairingId;

    private const KEY = 'abababababababababababababababababababababababababababababababab';

    private const BOOT = '11111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'database',
            'cache.default' => 'array', 'iot.enabled' => true]);
        DB::purge('sqlite');
        $this->assertSame('testing', app()->environment());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->withoutVite();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->device = new IotDevice;
        $this->device->forceFill(['id' => (string) Str::uuid(), 'name' => 'Synthetic ESP32-CAM',
            'credential' => self::KEY, 'credential_version' => 1])->save();
        $this->browser = $this->browser($this->admin);
        $this->scanners = app(IotScannerSessions::class);
    }

    private function browser(User $user): Request
    {
        $store = new Store('test', new ArraySessionHandler(120));
        $store->start();
        DB::table('sessions')->insert(['id' => $store->getId(), 'user_id' => $user->id,
            'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
        $request = Request::create('/admin/iot-scanner/poll', 'POST');
        $request->setLaravelSession($store);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function api(string $path, array $data, ?array $headers = null)
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $headers ??= $this->signed($path, $body);
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'off', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    private function signed(string $path, string $body): array
    {
        $headers = ['X-SB-Device' => $this->device->id, 'X-SB-Key-Version' => '1',
            'X-SB-Timestamp' => (string) now()->timestamp, 'X-SB-Nonce' => bin2hex(random_bytes(16))];
        $canonical = implode("\n", ['SB-IOT-1', 'POST', $path, $headers['X-SB-Device'], '1',
            $headers['X-SB-Timestamp'], $headers['X-SB-Nonce'], hash('sha256', $body)]);
        $headers['X-SB-Signature'] = hash_hmac('sha256', $canonical, hex2bin(self::KEY));

        return $headers;
    }

    private function pair(): void
    {
        $this->challenge = $this->scanners->challenge($this->browser, $this->device->id)['challenge'];
        $this->pairingId = $this->api('/api/iot/v1/pair', ['challenge' => $this->challenge, 'boot_id' => self::BOOT])
            ->assertOk()->json('pairing_id');
    }

    private function scan(string $payload = 'invalid', array $replace = []): array
    {
        return array_replace(['pairing_id' => $this->pairingId, 'boot_id' => self::BOOT,
            'scanner_session' => 1, 'sequence' => 1, 'captured_at_ms' => (int) now()->getTimestampMs(), 'payload' => $payload], $replace);
    }

    public function test_pairing_is_hashed_session_bound_encrypted_and_one_time(): void
    {
        $this->pair();
        $pairing = IotScannerPairing::first();
        $this->assertNull($pairing->challenge_hash);
        $this->assertNotSame($this->browser->session()->getId(), $pairing->getRawOriginal('session_id'));
        $this->assertStringNotContainsString($this->challenge, json_encode($pairing->toArray()));
        $this->api('/api/iot/v1/pair', ['challenge' => $this->challenge, 'boot_id' => self::BOOT])->assertStatus(409);
        $other = $this->browser($this->admin);
        $this->assertSame('unpaired', $this->scanners->poll($other)['state']);
        $this->assertFalse($this->scanners->devices($other)[0]['available']);
        $this->expectException(HttpException::class);
        $this->scanners->challenge($other, $this->device->id);
    }

    public static function appointmentClocks(): array
    {
        return ['current clock' => [null], 'after 2026 at a year boundary' => ['2035-12-31 23:59:59 UTC']];
    }

    #[DataProvider('appointmentClocks')]
    public function test_valid_scan_is_single_delivery_and_never_mutates_business_records(?string $clock): void
    {
        if ($clock !== null) {
            $this->travelTo(Carbon::parse($clock));
            $this->browser = $this->browser($this->admin);
        }
        $this->pair();
        $resident = User::factory()->create(['is_active' => true, 'role' => UserRole::Resident]);
        $service = Service::create(['name' => 'Synthetic Clearance']);
        $schedule = Schedule::create(['date' => now('Asia/Manila')->addDay()->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10]);
        $this->assertTrue($schedule->startsInFuture());
        $this->assertTrue(Schedule::upcoming()->whereKey($schedule->id)->exists());
        $reservation = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id,
            'status' => ReservationStatus::ReadyForPickup]);
        $ticket = app(QrTicketIssuer::class)->issue($reservation);
        $before = $reservation->fresh()->getRawOriginal();
        $response = $this->api('/api/iot/v1/scans', $this->scan($ticket->encodedPayload()))->assertOk()->assertJsonPath('valid', true);
        $this->assertStringNotContainsString($resident->name, $response->getContent());
        $this->assertStringNotContainsString($ticket->qr_payload, $response->getContent());
        $this->assertStringNotContainsString($ticket->qr_payload, IotScanEvent::first()->getRawOriginal('payload'));
        $this->assertSame('unpaired', $this->scanners->poll($this->browser($this->admin))['state']);
        $result = $this->scanners->poll($this->browser)['result'];
        $this->assertTrue($result['valid']);
        $this->assertSame(route('admin.reservations.show', $reservation), $result['reservation_url']);
        $this->assertSame($reservation->id, $this->browser->session()->get(CheckInReservations::SESSION_KEY)['reservation_id']);
        $this->assertNull($this->scanners->poll($this->browser)['result']);
        $this->assertNull(IotScanEvent::first()->getRawOriginal('payload'));
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
        $this->assertNull($ticket->fresh()->used_at);
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->assertDatabaseCount('reservation_payments', 0);
        $this->assertDatabaseCount('reservation_status_histories', 0);
    }

    public static function rejectedScans(): array
    {
        return [['sequence', 0, 422], ['sequence', '1', 422], ['scanner_session', 0, 422],
            ['boot_id', '22222222222222222222222222222222', 409],
            ['pairing_id', '22222222-2222-4222-8222-222222222222', 409],
            ['captured_at_ms', -5000, 422], ['captured_at_ms', 1000, 422]];
    }

    #[DataProvider('rejectedScans')]
    public function test_stale_future_wrong_boot_lease_and_malformed_scans_are_rejected(string $field, mixed $value, int $status): void
    {
        $this->pair();
        if ($field === 'captured_at_ms') {
            $value += (int) now()->getTimestampMs();
        }
        $this->api('/api/iot/v1/scans', $this->scan(replace: [$field => $value]))->assertStatus($status);
        $this->assertDatabaseCount('iot_scan_events', 0);
    }

    public function test_event_identity_nonce_replay_and_backwards_sessions_are_rejected(): void
    {
        $this->pair();
        $data = $this->scan();
        $headers = $this->signed('/api/iot/v1/scans', json_encode($data));
        $this->api('/api/iot/v1/scans', $data, $headers)->assertOk()->assertJsonPath('valid', false);
        $this->api('/api/iot/v1/scans', $data, $headers)->assertUnauthorized();
        $this->api('/api/iot/v1/scans', $data)->assertStatus(409);
        $this->api('/api/iot/v1/scans', $this->scan(replace: ['scanner_session' => 2]))->assertOk();
        $this->api('/api/iot/v1/scans', $this->scan(replace: ['sequence' => 2]))->assertStatus(409);
        $this->assertDatabaseCount('iot_scan_events', 2);
    }

    public function test_event_expires_without_result_and_disconnect_cascades_and_clears_evidence(): void
    {
        $this->pair();
        $this->api('/api/iot/v1/scans', $this->scan())->assertOk();
        $this->travel(5)->seconds();
        $this->assertNull($this->scanners->poll($this->browser)['result']);
        $this->browser->session()->put(CheckInReservations::SESSION_KEY, ['old' => true]);
        $this->scanners->disconnect($this->browser);
        $this->assertNull($this->browser->session()->get(CheckInReservations::SESSION_KEY));
        $this->assertDatabaseCount('iot_scanner_pairings', 0);
        $this->assertDatabaseCount('iot_scan_events', 0);
        $this->api('/api/iot/v1/scans', $this->scan())->assertStatus(409);
    }

    public function test_slow_verification_cannot_extend_five_second_event_freshness(): void
    {
        $this->pair();
        $this->api('/api/iot/v1/scans', $this->scan())->assertOk();
        $this->mock(QrTicketVerifier::class)->shouldReceive('verify')->twice()->andReturnUsing(function () {
            $this->travel(5)->seconds();

            return ['valid' => false, 'message' => 'Unknown or invalid QR ticket.'];
        });
        $this->assertNull($this->scanners->poll($this->browser)['result']);
        $this->assertNull($this->browser->session()->get(CheckInReservations::SESSION_KEY));
        $this->api('/api/iot/v1/scans', $this->scan(replace: ['sequence' => 2]))->assertStatus(422);
        $this->assertDatabaseCount('iot_scan_events', 1);
        $this->assertNull(IotScanEvent::first()->getRawOriginal('payload'));
    }

    public function test_housekeeping_preserves_fresh_events_and_removes_expired_private_state(): void
    {
        $this->pair();
        $this->api('/api/iot/v1/scans', $this->scan())->assertOk();
        $this->api('/api/iot/v1/scans', $this->scan(replace: ['sequence' => 2]))->assertOk();
        IotScanEvent::where('sequence', 1)->update(['expires_at' => now()->subSecond(),
            'captured_at_ms' => (int) now()->getTimestampMs() - 10000]);
        Artisan::call('smartbarangay:prune-iot-scanner-state');
        $this->assertDatabaseCount('iot_scan_events', 1);
        $this->assertSame(2, (int) IotScanEvent::first()->sequence);
        $this->assertDatabaseCount('iot_scanner_pairings', 1);
        $this->travel(11)->minutes();
        Artisan::call('smartbarangay:prune-iot-scanner-state');
        $this->assertDatabaseCount('iot_scan_events', 0);
        $this->assertDatabaseCount('iot_scanner_pairings', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_wrong_device_missing_signature_and_cross_admin_cannot_claim_pairing(): void
    {
        $challenge = $this->scanners->challenge($this->browser, $this->device->id)['challenge'];
        $body = ['challenge' => $challenge, 'boot_id' => self::BOOT];
        $this->api('/api/iot/v1/pair', $body, [])->assertUnauthorized();
        $headers = $this->signed('/api/iot/v1/pair', json_encode($body));
        $headers['X-SB-Signature'] = str_repeat('0', 64);
        $this->api('/api/iot/v1/pair', $body, $headers)->assertUnauthorized();
        $original = $this->device;
        $this->device = new IotDevice;
        $this->device->forceFill(['id' => (string) Str::uuid(), 'name' => 'Other synthetic scanner',
            'credential' => self::KEY, 'credential_version' => 1])->save();
        $this->api('/api/iot/v1/pair', $body)->assertStatus(409);
        $this->device = $original;
        $other = $this->browser(User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]));
        $this->assertSame('unpaired', $this->scanners->poll($other)['state']);
        $this->assertFalse($this->scanners->devices($other)[0]['available']);
        $this->assertNull(IotScannerPairing::first()->paired_at);
    }

    #[DataProvider('appointmentClocks')]
    public function test_completed_ticket_is_rejected_without_exposing_or_changing_reservation(?string $clock): void
    {
        if ($clock !== null) {
            $this->travelTo(Carbon::parse($clock));
            $this->browser = $this->browser($this->admin);
        }
        $this->pair();
        $resident = User::factory()->create(['is_active' => true]);
        $service = Service::create(['name' => 'Synthetic historical service']);
        $schedule = Schedule::create(['date' => now('Asia/Manila')->addDay()->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 1]);
        $this->assertTrue($schedule->startsInFuture());
        $this->assertTrue(Schedule::upcoming()->whereKey($schedule->id)->exists());
        $reservation = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id,
            'status' => ReservationStatus::Approved]);
        $ticket = app(QrTicketIssuer::class)->issue($reservation);
        $reservation->forceFill(['status' => ReservationStatus::Completed])->save();
        $this->api('/api/iot/v1/scans', $this->scan($ticket->encodedPayload()))->assertOk()->assertJsonPath('valid', false);
        $result = $this->scanners->poll($this->browser)['result'];
        $this->assertFalse($result['valid']);
        $this->assertArrayNotHasKey('reservation', $result);
        $this->assertArrayNotHasKey('reservation_url', $result);
        $this->assertNull($this->browser->session()->get(CheckInReservations::SESSION_KEY));
        $this->assertSame(ReservationStatus::Completed, $reservation->fresh()->status);
        $this->assertNull($ticket->fresh()->used_at);
    }

    public static function revocations(): array
    {
        return [['logout'], ['password'], ['inactive'], ['unverified'], ['role'], ['device'], ['rotation'], ['heartbeat'], ['lease']];
    }

    #[DataProvider('revocations')]
    public function test_revoked_session_account_device_or_lease_cannot_scan(string $case): void
    {
        $this->pair();
        match ($case) {
            'logout' => DB::table('sessions')->where('id', $this->browser->session()->getId())->delete(),
            'password' => $this->admin->forceFill(['password' => 'Changed-synthetic-password!'])->save(),
            'inactive' => $this->admin->forceFill(['is_active' => false])->save(),
            'unverified' => $this->admin->forceFill(['email_verified_at' => null])->save(),
            'role' => $this->admin->forceFill(['role' => UserRole::Resident])->save(),
            'device' => $this->device->forceFill(['revoked_at' => now()])->save(),
            'rotation' => $this->device->forceFill(['credential_version' => 2])->save(),
            'heartbeat' => $this->travel(16)->seconds(),
            'lease' => IotScannerPairing::query()->update(['expires_at' => now()->subSecond()]),
        };
        $this->api('/api/iot/v1/scans', $this->scan())->assertStatus(in_array($case, ['device', 'rotation']) ? 401 : 409);
        $this->assertDatabaseCount('iot_scan_events', 0);
    }

    public function test_expired_wrong_and_superseded_pairing_challenges_cannot_pair(): void
    {
        $old = $this->scanners->challenge($this->browser, $this->device->id)['challenge'];
        $current = $this->scanners->challenge($this->browser, $this->device->id)['challenge'];
        $this->assertNotSame($old, $current);
        $this->api('/api/iot/v1/pair', ['challenge' => $old, 'boot_id' => self::BOOT])->assertStatus(409);
        $this->assertSame(hash('sha256', $current), IotScannerPairing::first()->challenge_hash);
        $this->travel(90)->seconds();
        $this->api('/api/iot/v1/pair', ['challenge' => $current, 'boot_id' => self::BOOT])->assertStatus(409);
    }

    public function test_browser_endpoints_require_active_verified_admin_and_csrf(): void
    {
        $this->postJson('/admin/iot-scanner/poll')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_active' => true]))->postJson('/admin/iot-scanner/poll')->assertForbidden();
        $this->admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->admin)->postJson('/admin/iot-scanner/poll')->assertForbidden();
        $this->admin->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->actingAs($this->admin)->postJson('/admin/iot-scanner/poll')->assertRedirect(route('login'));
        $this->admin->forceFill(['is_active' => true])->save();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->actingAs($this->admin)->postJson('/admin/iot-scanner/challenge', ['device_id' => $this->device->id])->assertStatus(419);
        $this->assertDatabaseCount('iot_scanner_pairings', 0);
    }

    public function test_feature_off_preserves_laptop_camera_and_fails_closed_without_migration(): void
    {
        config(['iot.enabled' => false]);
        $this->actingAs($this->admin)->get('/admin/qr-verification')->assertOk()->assertSee('Laptop Camera')->assertSee('Start Camera')->assertSee('IoT Scanner is unavailable');
        $this->postJson('/admin/iot-scanner/poll')->assertStatus(503);
        $this->api('/api/iot/v1/pair', [])->assertStatus(503);
        config(['iot.enabled' => true, 'session.driver' => 'array']);
        $this->postJson('/admin/iot-scanner/poll')->assertStatus(503);
    }

    public function test_parallel_pairing_and_parallel_result_claim_are_single_use(): void
    {
        $challenge = $this->scanners->challenge($this->browser, $this->device->id)['challenge'];
        $file = sys_get_temp_dir().'/sb-iot03-race-'.Str::uuid().'.sqlite';
        DB::statement('VACUUM INTO ?', [$file]);
        try {
            $input = ['mode' => 'pair', 'database' => $file, 'key' => config('app.key'), 'device' => $this->device->id,
                'challenge' => $challenge, 'session' => $this->browser->session()->getId(), 'admin' => $this->admin->id,
                'binding' => $this->browser->session()->get(IotScannerSessions::BINDING_KEY)];
            $run = function (array $data): array {
                $processes = [];
                for ($i = 0; $i < 2; $i++) {
                    $process = new Process([PHP_BINARY, base_path('tests/Fixtures/iot-scanner-race.php')], base_path(), ['APP_ENV' => 'testing']);
                    $process->setInput(json_encode($data))->start();
                    $processes[] = $process;
                }

                return array_map(function (Process $process) {
                    $process->wait();
                    $this->assertTrue($process->isSuccessful());

                    return trim($process->getOutput());
                }, $processes);
            };
            $results = $run($input);
            $this->assertCount(1, array_filter($results, fn ($value) => $value === 'paired'));
            config(['database.connections.race' => array_replace(config('database.connections.sqlite'), ['database' => $file])]);
            $pairingId = DB::connection('race')->table('iot_scanner_pairings')->value('id');
            DB::connection('race')->table('iot_scan_events')->insert(['id' => (string) Str::uuid(), 'pairing_id' => $pairingId,
                'boot_id' => self::BOOT, 'scanner_session' => 1, 'sequence' => 1, 'captured_at_ms' => (int) now()->getTimestampMs(),
                'expires_at' => now()->addSeconds(5), 'payload' => encrypt('synthetic-invalid', false)]);
            $results = $run(array_replace($input, ['mode' => 'poll']));
            $this->assertCount(1, array_filter($results, fn ($value) => $value === 'delivered'));
            $this->assertNull(DB::connection('race')->table('iot_scan_events')->value('payload'));
        } finally {
            DB::purge('race');
            foreach ([$file, $file.'-wal', $file.'-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
