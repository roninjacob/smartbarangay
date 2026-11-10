<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\IotDevice;
use App\Models\IotDeviceState;
use App\Models\User;
use App\Services\IotAutomaticScanner;
use App\Services\IotMonitoring;
use App\Services\IotScannerSessions;
use App\Services\IotTelemetry;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IotMonitoringTest extends TestCase
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

    private function heartbeat(array $telemetry = [], array $replace = [])
    {
        return $this->api('/api/iot/v1/status', array_replace(['boot_id' => self::BOOT,
            'captured_at_ms' => now()->getTimestampMs(), 'camera_state' => 'ready',
            'telemetry' => array_replace(['firmware' => 'sb-iot-16.3', 'heap' => 55000, 'largest' => 24000,
                'rssi' => -42, 'error' => 'ok', 'http_code' => 200, 'attempts' => 32, 'decodes' => 2,
                'avg_us' => 990000, 'p95_us' => 1100000], $telemetry)], $replace));
    }

    public function test_signed_telemetry_and_missing_legacy_fields_have_honest_values(): void
    {
        $this->heartbeat()->assertOk();
        $report = app(IotMonitoring::class)->report($this->device->id, 1);
        $this->assertTrue($report['device']['online']);
        $this->assertSame(55000, $report['device']['telemetry']['heap']);
        $this->assertArrayNotHasKey('psram', $report['device']['telemetry']);
        $this->assertNull($report['device']['counts_since']);
        $this->assertStringNotContainsString(self::KEY, json_encode($report));
        $this->assertStringNotContainsString('credential', json_encode($report));
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public function test_telemetry_rejects_unknown_sensitive_fields_bad_categories_and_non_integer_values(): void
    {
        $this->heartbeat(['password' => 'synthetic-secret'])->assertStatus(422);
        $this->heartbeat(['error' => '<script>alert(1)</script>'])->assertStatus(422);
        $this->heartbeat(['heap' => '55000'])->assertStatus(422);
        $this->heartbeat(['rssi' => -200])->assertStatus(422);
        $this->assertDatabaseCount('iot_device_states', 0);
        $this->assertDatabaseCount('iot_device_diagnostics', 0);
    }

    public function test_old_heartbeat_without_telemetry_still_works_and_reboot_discards_old_metrics(): void
    {
        $this->heartbeat()->assertOk();
        $this->travel(1)->seconds();
        $this->api('/api/iot/v1/status', ['boot_id' => str_repeat('2', 32), 'captured_at_ms' => now()->getTimestampMs(), 'camera_state' => 'ready'])->assertOk();
        $report = app(IotMonitoring::class)->report($this->device->id, 1);
        $this->assertSame([], (array) $report['device']['telemetry']);
        $this->assertNull($report['device']['telemetry_at']);
        $this->assertTrue($report['device']['online']);
    }

    public function test_offline_inference_and_scheduled_observations_resume_after_fresh_signed_heartbeat(): void
    {
        $this->heartbeat()->assertOk();
        $this->travel(16)->seconds();
        $this->assertFalse(app(IotMonitoring::class)->report($this->device->id, 1)['device']['online']);
        app(IotTelemetry::class)->prune();
        app(IotTelemetry::class)->prune();
        $this->assertSame(1, DB::table('iot_device_diagnostics')->where('kind', 'offline_observed')->count());
        $this->heartbeat()->assertOk();
        $this->assertTrue(app(IotMonitoring::class)->report($this->device->id, 1)['device']['online']);
        $this->assertSame(1, DB::table('iot_device_diagnostics')->where('kind', 'online_observed')->count());
    }

    public function test_monitoring_is_admin_only_and_handles_bad_device_ids_and_revocation(): void
    {
        $this->getJson('/admin/iot-dashboard/data?device='.$this->device->id)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]))
            ->getJson('/admin/iot-dashboard/data?device='.$this->device->id)->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/iot-dashboard')->assertOk()->assertSee('Device monitoring');
        $this->getJson('/admin/iot-dashboard/data?device=invalid')->assertStatus(422);
        $this->getJson('/admin/iot-dashboard/data?device='.Str::uuid())->assertNotFound();
        $this->heartbeat()->assertOk();
        $this->device->forceFill(['revoked_at' => now()])->save();
        $this->heartbeat()->assertUnauthorized();
        $this->getJson('/admin/iot-dashboard/data?device='.$this->device->id)->assertOk()->assertJsonPath('device.online', false)->assertJsonPath('device.revoked', true);
    }

    public function test_replayed_requests_cannot_insert_telemetry_and_status_rate_limit_remains_active(): void
    {
        $body = ['boot_id' => self::BOOT, 'captured_at_ms' => now()->getTimestampMs(), 'camera_state' => 'ready'];
        $headers = $this->signed('/api/iot/v1/status', json_encode($body));
        $this->api('/api/iot/v1/status', $body, $headers)->assertOk();
        $this->api('/api/iot/v1/status', $body, $headers)->assertUnauthorized();
        // The existing middleware budget also charges rejected replay attempts.
        for ($i = 0; $i < 18; $i++) {
            $this->assertSame(200, $this->api('/api/iot/v1/status', $body)->status(), 'Status request '.$i);
        }
        $this->api('/api/iot/v1/status', $body)->assertStatus(429);
        $this->assertDatabaseCount('checkin_logs', 0);
    }

    public function test_inactive_and_unverified_admins_cannot_read_monitoring(): void
    {
        foreach ([['is_active' => false], ['email_verified_at' => null]] as $attributes) {
            $admin = User::factory()->create(array_merge(['role' => UserRole::Admin, 'is_active' => true], $attributes));
            $response = $this->actingAs($admin)->getJson('/admin/iot-dashboard/data?device='.$this->device->id);
            if (! $admin->is_active) {
                $response->assertRedirect(route('login'));
            } else {
                $response->assertForbidden();
            }
        }
    }

    public function test_readonly_report_has_deterministic_database_pagination_and_escaped_names(): void
    {
        $this->device->forceFill(['name' => '<script>synthetic</script>'])->save();
        $this->heartbeat()->assertOk();
        for ($i = 0; $i < 20; $i++) {
            DB::table('iot_device_diagnostics')->insert([
                'device_id' => $this->device->id, 'kind' => 'scan_invalid', 'details' => '{}', 'recorded_at' => now('UTC')]);
        }
        $before = IotDeviceState::first()->getRawOriginal();
        $count = DB::table('iot_device_diagnostics')->count();
        $report = app(IotMonitoring::class)->report($this->device->id, 1);
        $this->assertCount(15, $report['events']);
        $this->assertSame('scan_invalid', $report['events'][0]['kind']);
        $this->assertSame($count, $report['pagination']['total']);
        $this->assertSame($before, IotDeviceState::first()->getRawOriginal());
        $this->assertSame($count, DB::table('iot_device_diagnostics')->count());
        $this->actingAs($this->admin)->get('/admin/iot-dashboard')->assertOk()
            ->assertSee('&lt;script&gt;synthetic&lt;/script&gt;', false)->assertDontSee('<script>synthetic</script>', false);
    }

    public function test_history_is_bounded_and_scan_counters_survive_transient_event_cleanup(): void
    {
        $this->heartbeat()->assertOk();
        $body = ['boot_id' => self::BOOT, 'scanner_session' => 1, 'sequence' => 1,
            'captured_at_ms' => now()->getTimestampMs(), 'payload' => 'SBQ1:'.Str::uuid()];
        $this->api('/api/iot/v1/scans', $body)->assertOk();
        $this->api('/api/iot/v1/scans', $body)->assertStatus(409);
        $this->assertSame(1, IotDeviceState::first()->scan_count);
        $this->assertSame(1, IotDeviceState::first()->invalid_count);
        DB::table('iot_device_diagnostics')->insert(array_fill(0, 505, ['device_id' => $this->device->id,
            'kind' => 'transport', 'details' => '{}', 'recorded_at' => now('UTC')->subDays(8)]));
        $this->travel(1)->seconds();
        $this->heartbeat(['error' => 'dns_timeout'])->assertOk();
        $this->assertLessThanOrEqual(500, DB::table('iot_device_diagnostics')->count());
        $this->travel(5)->seconds();
        app(IotAutomaticScanner::class)->prune();
        app(IotTelemetry::class)->prune();
        $this->assertDatabaseCount('iot_device_scan_events', 0);
        $this->assertSame(1, IotDeviceState::first()->scan_count);
        $this->assertSame(0, DB::table('iot_device_diagnostics')->where('recorded_at', '<=', now('UTC')->subDays(7))->count());
    }
}
