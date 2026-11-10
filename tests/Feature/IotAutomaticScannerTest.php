<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\IotDevice;
use App\Models\IotDeviceScanEvent;
use App\Models\IotDeviceState;
use App\Models\IotScannerPairing;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CheckInReservations;
use App\Services\IotAutomaticScanner;
use App\Services\IotScannerSessions;
use App\Services\QrTicketIssuer;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IotAutomaticScannerTest extends TestCase
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
        $this->travelTo(Carbon::parse('2026-11-10 04:00:00', 'UTC'));
        Storage::fake('local');
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

    private function scanBody(array $replace = []): array
    {
        return array_replace(['boot_id' => self::BOOT, 'scanner_session' => 1, 'sequence' => 1,
            'captured_at_ms' => (int) now()->getTimestampMs(), 'payload' => 'SBQ1:'.Str::uuid()], $replace);
    }

    private function automatic(): IotAutomaticScanner
    {
        return app(IotAutomaticScanner::class);
    }

    private function control(array $replace = [])
    {
        return $this->api('/api/iot/v1/status', array_replace(['boot_id' => self::BOOT,
            'captured_at_ms' => (int) now()->getTimestampMs(), 'camera_state' => 'ready'], $replace));
    }

    private function frameBody(array $replace = []): array
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return array_replace(['boot_id' => self::BOOT, 'captured_at_ms' => (int) now()->getTimestampMs(),
            'lease_id' => IotScannerPairing::first()->id, 'sequence' => 1, 'image' => base64_encode($jpeg)], $replace);
    }

    private function denied(callable $action, int $code): void
    {
        try {
            $action();
            $this->fail('Expected denied access');
        } catch (HttpException $error) {
            $this->assertSame($code, $error->getStatusCode());
        }
    }

    public function test_authenticated_scan_without_any_browser_or_pairing_is_recorded_privately(): void
    {
        $body = $this->scanBody();
        $this->api('/api/iot/v1/scans', $body)->assertOk()->assertJsonPath('valid', false);
        $this->assertDatabaseCount('iot_scanner_pairings', 0);
        $this->assertDatabaseCount('iot_device_scan_events', 1);
        $this->assertStringNotContainsString($body['payload'], IotDeviceScanEvent::first()->getRawOriginal('payload'));
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public function test_valid_scan_handoff_is_exclusive_once_only_and_never_checks_in_or_consumes_ticket(): void
    {
        $this->automatic()->connect($this->browser, $this->device->id);
        $resident = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $service = Service::create(['name' => 'Synthetic automatic clearance']);
        $schedule = Schedule::create(['date' => now('Asia/Manila')->addDay()->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10]);
        $reservation = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => ReservationStatus::ReadyForPickup]);
        $ticket = app(QrTicketIssuer::class)->issue($reservation);
        $before = $reservation->fresh()->getRawOriginal();
        $response = $this->api('/api/iot/v1/scans', $this->scanBody(['payload' => $ticket->encodedPayload()]))->assertOk()->assertJsonPath('valid', true);
        $this->assertStringNotContainsString($ticket->qr_payload, $response->getContent());
        $other = $this->browser($this->admin);
        $this->denied(fn () => $this->automatic()->poll($other), 409);
        $result = $this->automatic()->poll($this->browser)['result'];
        $this->assertTrue($result['valid']);
        $this->assertSame(route('admin.reservations.show', $reservation), $result['reservation_url']);
        $this->assertSame($reservation->id, $this->browser->session()->get(CheckInReservations::SESSION_KEY)['reservation_id']);
        $this->assertNull($this->automatic()->poll($this->browser)['result']);
        $this->assertNull(IotDeviceScanEvent::first()->getRawOriginal('payload'));
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
        $this->assertNull($ticket->fresh()->used_at);
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->assertDatabaseCount('reservation_payments', 0);
    }

    public function test_nonce_replay_tampering_and_revocation_rejected(): void
    {
        $body = $this->scanBody();
        $headers = $this->signed('/api/iot/v1/scans', json_encode($body));
        $this->api('/api/iot/v1/scans', $body, $headers)->assertOk();
        $this->api('/api/iot/v1/scans', $body, $headers)->assertUnauthorized();
        $tampered = $this->scanBody(['sequence' => 2]);
        $this->api('/api/iot/v1/scans', $tampered, $headers)->assertUnauthorized();
        $this->device->forceFill(['revoked_at' => now()])->save();
        $this->api('/api/iot/v1/scans', $tampered)->assertUnauthorized();
        $this->control()->assertUnauthorized();
        $this->assertDatabaseCount('iot_device_scan_events', 1);
    }

    public function test_new_nonce_does_not_bypass_duplicate_sequence_or_ticket_cooldown(): void
    {
        $body = $this->scanBody();
        $this->api('/api/iot/v1/scans', $body)->assertOk();
        $this->api('/api/iot/v1/scans', $body)->assertStatus(409);
        $this->api('/api/iot/v1/scans', array_replace($body, ['sequence' => 2]))->assertStatus(409);
        $this->api('/api/iot/v1/scans', $this->scanBody(['sequence' => 2, 'captured_at_ms' => now()->getTimestampMs() - 5000]))->assertStatus(422);
        $this->assertDatabaseCount('iot_device_scan_events', 1);
    }

    public function test_online_status_camera_error_and_offline_expiry(): void
    {
        $this->automatic()->connect($this->browser, $this->device->id);
        $this->control(['camera_state' => 'camera_error'])->assertOk()->assertJsonPath('preview', false);
        $poll = $this->automatic()->poll($this->browser);
        $this->assertTrue($poll['online']);
        $this->assertSame('camera_error', $poll['camera_state']);
        for ($i = 0; $i < 4; $i++) {
            $this->travel(5)->seconds();
            $poll = $this->automatic()->poll($this->browser);
        }
        $this->assertFalse($poll['online']);
    }

    public function test_preview_requires_authorized_admin_lease_and_real_bounded_jpeg(): void
    {
        $this->automatic()->connect($this->browser, $this->device->id);
        $this->control()->assertOk();
        $body = $this->frameBody();
        $this->api('/api/iot/v1/preview', $body)->assertStatus(409);
        $this->automatic()->preview($this->browser, true);
        $this->control()->assertOk()->assertJsonPath('preview', true);
        $this->api('/api/iot/v1/preview', $body)->assertOk();
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($this->automatic()->frame($this->browser))[2]);
        $this->denied(fn () => $this->automatic()->frame($this->browser($this->admin)), 409);
        $this->api('/api/iot/v1/preview', $body)->assertStatus(409);
        $this->travel(2)->seconds();
        $this->api('/api/iot/v1/preview', $this->frameBody(['sequence' => 2, 'image' => base64_encode('not a jpeg')]))->assertStatus(422);
        $this->automatic()->preview($this->browser, false);
        $this->control()->assertOk()->assertJsonPath('preview', false);
        Storage::disk('local')->assertMissing('iot-preview/'.$this->device->id.'.frame');
        $this->denied(fn () => $this->automatic()->frame($this->browser), 404);
    }

    public function test_preview_stops_on_expiration_session_logout_disconnect_and_revocation(): void
    {
        $this->automatic()->connect($this->browser, $this->device->id);
        $this->control()->assertOk();
        $this->automatic()->preview($this->browser, true);
        $this->api('/api/iot/v1/preview', $this->frameBody())->assertOk();
        $this->travel(6)->seconds();
        $this->denied(fn () => $this->automatic()->frame($this->browser), 404);
        $this->automatic()->prune();
        Storage::disk('local')->assertMissing('iot-preview/'.$this->device->id.'.frame');
        $this->travel(5)->seconds();
        $this->control()->assertOk()->assertJsonPath('preview', false);
        $this->automatic()->poll($this->browser);
        $this->automatic()->preview($this->browser, true);
        DB::table('sessions')->delete();
        $this->control()->assertOk()->assertJsonPath('preview', false);
        $this->denied(fn () => $this->automatic()->frame($this->browser), 409);
        $this->scanners->disconnect($this->browser);
        $this->assertDatabaseCount('iot_scanner_pairings', 0);
        $this->device->forceFill(['revoked_at' => now()])->save();
        $this->api('/api/iot/v1/preview', ['boot_id' => self::BOOT])->assertUnauthorized();
    }

    public function test_browser_endpoints_are_admin_only_and_connect_never_emits_pairing_qr(): void
    {
        $this->getJson('/admin/iot-scanner/frame')->assertUnauthorized();
        $this->postJson('/admin/iot-scanner/connect', ['device_id' => $this->device->id])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_active' => true, 'role' => UserRole::Resident]))
            ->getJson('/admin/iot-scanner/frame')->assertForbidden();
        $response = $this->actingAs($this->admin)->postJson('/admin/iot-scanner/connect', ['device_id' => $this->device->id])->assertOk();
        $this->assertArrayNotHasKey('challenge', $response->json());
        $this->assertArrayNotHasKey('qr_svg', $response->json());
        $this->actingAs($this->admin)->get('/admin/qr-verification')->assertOk()->assertSee('Camera Preview')->assertDontSee('Generate Pairing QR');
    }

    public function test_heartbeat_boot_change_and_untrusted_payloads_fail_closed(): void
    {
        $this->control()->assertOk();
        $this->travel(1)->seconds();
        $this->control(['boot_id' => str_repeat('2', 32)])->assertOk();
        $this->api('/api/iot/v1/scans', $this->scanBody())->assertStatus(409);
        $this->control(['camera_state' => '<script>alert(1)</script>'])->assertStatus(422);
        $this->api('/api/iot/v1/preview', ['image' => str_repeat('a', 46000)])->assertStatus(413);
        $this->assertDatabaseCount('iot_device_scan_events', 0);
    }

    public function test_only_fresh_scans_after_the_current_admin_connection_are_delivered(): void
    {
        $this->api('/api/iot/v1/scans', $this->scanBody())->assertOk();
        $this->travel(1)->seconds();
        $this->automatic()->connect($this->browser, $this->device->id);
        $this->assertNull($this->automatic()->poll($this->browser)['result']);
        $this->api('/api/iot/v1/scans', $this->scanBody(['sequence' => 2]))->assertOk();
        $this->travel(5)->seconds();
        $this->assertNull($this->automatic()->poll($this->browser)['result']);
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->assertFalse($this->browser->session()->has(CheckInReservations::SESSION_KEY));
    }

    public function test_latest_frame_is_private_reencoded_bounded_and_revocation_blocks_browser_access(): void
    {
        $this->automatic()->connect($this->browser, $this->device->id);
        $this->control()->assertOk();
        $this->automatic()->preview($this->browser, true);
        $image = imagecreatetruecolor(640, 480);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        imagedestroy($image);
        $this->api('/api/iot/v1/preview', $this->frameBody(['image' => base64_encode($jpeg)]))->assertOk();
        $this->assertSame(320, getimagesizefromstring($this->automatic()->frame($this->browser))[0]);
        $path = 'iot-preview/'.$this->device->id.'.frame';
        $this->assertStringNotContainsString('JFIF', Storage::disk('local')->get($path));
        $this->travel(2)->seconds();
        $this->api('/api/iot/v1/preview', $this->frameBody(['sequence' => 2]))->assertOk();
        $this->assertCount(1, Storage::disk('local')->files('iot-preview'));
        $this->api('/api/iot/v1/preview', $this->frameBody(['lease_id' => (string) Str::uuid(), 'sequence' => 3]))->assertStatus(409);
        $this->device->forceFill(['revoked_at' => now()])->save();
        $this->denied(fn () => $this->automatic()->frame($this->browser), 409);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public function test_inactive_unverified_admin_and_modified_password_cannot_keep_preview_alive(): void
    {
        $this->automatic()->connect($this->browser, $this->device->id);
        $this->control()->assertOk();
        $this->automatic()->preview($this->browser, true);
        $this->admin->forceFill(['is_active' => false])->save();
        $this->control()->assertOk()->assertJsonPath('preview', false);
        $this->admin->forceFill(['is_active' => true, 'email_verified_at' => null])->save();
        $this->control()->assertOk()->assertJsonPath('preview', false);
        $this->admin->forceFill(['email_verified_at' => now(), 'password' => 'NewSyntheticPassword123!'])->save();
        $this->control()->assertOk()->assertJsonPath('preview', false);
    }

    public function test_heartbeat_compares_device_capture_times_instead_of_server_receipt_times(): void
    {
        $this->control(['captured_at_ms' => now()->getTimestampMs() - 2000])->assertOk();
        $this->travel(1)->seconds();
        // Last-seen is a server timestamp; ordinary bounded device clock skew remains valid.
        $this->control(['captured_at_ms' => now()->getTimestampMs() - 2000])->assertOk();
        $this->control(['boot_id' => str_repeat('3', 32), 'captured_at_ms' => now()->getTimestampMs() - 3000])->assertStatus(409);
        $this->assertSame(self::BOOT, IotDeviceState::first()->boot_id);
    }
}
