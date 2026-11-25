<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\IotDevice;
use App\Models\IotDeviceCommand;
use App\Models\IotDeviceState;
use App\Models\User;
use App\Services\IotAutomaticScanner;
use App\Services\IotDeviceControl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IotDeviceControlTest extends TestCase
{
    private IotDevice $device;

    private User $admin;

    private const KEY = 'abababababababababababababababababababababababababababababababab';

    private const BOOT = '11111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'database',
            'cache.default' => 'array', 'iot.enabled' => true]);
        DB::purge('sqlite');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->travelTo(Carbon::parse('2026-11-10 04:00:00', 'UTC'));
        Storage::fake('local');
        $this->withoutVite();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->device = new IotDevice;
        $this->device->forceFill(['id' => (string) Str::uuid(), 'name' => 'Synthetic ESP32-CAM',
            'credential' => self::KEY, 'credential_version' => 1])->save();
    }

    private function api(string $path, array $data)
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $headers = ['X-SB-Device' => $this->device->id, 'X-SB-Key-Version' => '1',
            'X-SB-Timestamp' => (string) now()->timestamp, 'X-SB-Nonce' => bin2hex(random_bytes(16))];
        $canonical = implode("\n", ['SB-IOT-1', 'POST', $path, $headers['X-SB-Device'], '1',
            $headers['X-SB-Timestamp'], $headers['X-SB-Nonce'], hash('sha256', $body)]);
        $headers['X-SB-Signature'] = hash_hmac('sha256', $canonical, hex2bin(self::KEY));
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'off', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    /** Signed heartbeat; $telemetry carries what the scanner reports about its control state. */
    private function heartbeat(array $telemetry = [], string $boot = self::BOOT)
    {
        $body = ['boot_id' => $boot, 'captured_at_ms' => (int) now()->getTimestampMs(), 'camera_state' => 'ready'];
        if ($telemetry) {
            $body['telemetry'] = $telemetry;
        }

        return $this->api('/api/iot/v1/status', $body)->assertOk();
    }

    private function request(bool $enabled, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->postJson(route('admin.iot-dashboard.scanner', $this->device->id), ['enabled' => $enabled]);
    }

    private function restart(?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->postJson(route('admin.iot-dashboard.restart', $this->device->id), ['confirm' => true]);
    }

    private function report(): array
    {
        return app(IotDeviceControl::class)->report($this->device->id, IotDeviceState::find($this->device->id));
    }

    public function test_disable_is_pending_until_the_scanner_acknowledges_it(): void
    {
        $this->heartbeat(['control_revision' => 0, 'scanner_enabled' => 1])->assertJsonPath('control.enabled', true);
        $this->request(false)->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('revision', 1);
        $this->assertSame(['revision' => 0, 'enabled' => true], $this->report()['actual']);

        $this->travel(1)->seconds();
        $this->heartbeat(['control_revision' => 0, 'scanner_enabled' => 1])
            ->assertJsonPath('control.enabled', false)->assertJsonPath('control.revision', 1);
        $this->assertSame('delivered', IotDeviceCommand::first()->status);
        $this->assertFalse($this->report()['actual']['enabled'] === false, 'Delivery is not confirmation.');

        $this->travel(1)->seconds();
        $this->heartbeat(['control_revision' => 1, 'scanner_enabled' => 0]);
        $this->assertSame('applied', IotDeviceCommand::first()->status);
        $this->assertSame(['revision' => 1, 'enabled' => false], $this->report()['actual']);
        $this->assertNotNull($this->report()['commands'][0]['latency_ms']);
    }

    public function test_disabled_scanner_cannot_verify_or_upload_preview(): void
    {
        $this->request(false)->assertOk();
        $this->heartbeat(['control_revision' => 1, 'scanner_enabled' => 0])->assertJsonPath('preview', false);
        $this->api('/api/iot/v1/scans', ['boot_id' => self::BOOT, 'scanner_session' => 1, 'sequence' => 1,
            'captured_at_ms' => (int) now()->getTimestampMs(), 'payload' => 'SBQ1:'.Str::uuid()])->assertStatus(409);
        $this->assertDatabaseCount('iot_device_scan_events', 0);
        $this->assertDatabaseCount('checkin_logs', 0);
        // Still a healthy, connected device.
        $this->assertNotNull(IotDeviceState::find($this->device->id)->heartbeat_at);
    }

    public function test_rapid_requests_resolve_to_the_latest_and_old_acknowledgments_confirm_nothing_newer(): void
    {
        $this->request(false)->assertOk()->assertJsonPath('revision', 1);
        $this->request(false)->assertOk()->assertJsonPath('revision', 1); // duplicate: same command
        $this->request(true)->assertOk()->assertJsonPath('revision', 2);
        $this->request(false)->assertOk()->assertJsonPath('revision', 3);
        $this->assertSame(['superseded', 'superseded', 'pending'], IotDeviceCommand::orderBy('revision')->pluck('status')->all());
        $this->assertSame(['revision' => 3, 'enabled' => false], app(IotDeviceControl::class)->desired($this->device->id));

        // A delayed report of revision 1 does not confirm revision 3.
        $this->heartbeat(['control_revision' => 1, 'scanner_enabled' => 0])->assertJsonPath('control.revision', 3);
        $this->assertSame('delivered', IotDeviceCommand::where('revision', 3)->value('status'));
        $this->travel(1)->seconds();
        $this->heartbeat(['control_revision' => 3, 'scanner_enabled' => 0]);
        $this->assertSame('applied', IotDeviceCommand::where('revision', 3)->value('status'));
    }

    public function test_desired_state_survives_reconnects_and_restarts(): void
    {
        $this->request(false)->assertOk();
        // A new boot (power cycle) reporting its persisted revision is told the same desired state.
        $this->heartbeat(['control_revision' => 1, 'scanner_enabled' => 0], str_repeat('2', 32))->assertJsonPath('control.enabled', false);
        $this->travel(10)->minutes();
        $this->heartbeat(['control_revision' => 1, 'scanner_enabled' => 0], str_repeat('2', 32))->assertJsonPath('control.enabled', false);
        $this->assertDatabaseCount('iot_device_commands', 1);
    }

    public function test_restart_is_confirmed_only_by_a_new_boot_and_never_repeats(): void
    {
        $this->heartbeat(['control_revision' => 0, 'scanner_enabled' => 1, 'restart_revision' => 0]);
        $first = $this->restart()->assertOk()->assertJsonPath('status', 'pending')->json('id');
        $this->assertSame($first, $this->restart()->assertOk()->json('id'), 'An open restart is not duplicated.');
        $this->heartbeat(['control_revision' => 0, 'scanner_enabled' => 1, 'restart_revision' => 0])->assertJsonPath('control.restart', 1);

        // Acknowledged on the same boot: restarting, not complete.
        $this->travel(1)->seconds();
        $this->heartbeat(['control_revision' => 0, 'scanner_enabled' => 1, 'restart_revision' => 1])->assertJsonPath('control.restart', null);
        $this->assertSame('restarting', IotDeviceCommand::find($first)->status);

        // A different boot proves the restart.
        $this->travel(15)->seconds();
        $this->heartbeat(['control_revision' => 0, 'scanner_enabled' => 1, 'restart_revision' => 1, 'reset' => 3], str_repeat('3', 32))
            ->assertJsonPath('control.restart', null);
        $this->assertSame(['applied', 'new_boot_observed'], [IotDeviceCommand::find($first)->status, IotDeviceCommand::find($first)->result]);
        $this->assertDatabaseHas('iot_device_diagnostics', ['kind' => 'restart_confirmed']);
    }

    public function test_restart_expires_when_undelivered_and_fails_without_a_new_boot(): void
    {
        $undelivered = $this->restart()->json('id');
        $this->travel(61)->seconds();
        $this->assertSame('expired', $this->report()['commands'][0]['status']);
        $this->assertSame('expired', IotDeviceCommand::find($undelivered)->status);

        $second = $this->restart()->json('id');
        $this->heartbeat(['control_revision' => 0, 'scanner_enabled' => 1, 'restart_revision' => 0]);
        $this->heartbeat(['control_revision' => 0, 'scanner_enabled' => 1, 'restart_revision' => 2]);
        $this->travel(91)->seconds();
        $this->heartbeat(['control_revision' => 0, 'scanner_enabled' => 1, 'restart_revision' => 2]);
        $this->assertSame(['failed', 'no_new_boot_observed'], [IotDeviceCommand::find($second)->status, IotDeviceCommand::find($second)->result]);
    }

    public function test_only_active_verified_admins_can_control_a_scanner(): void
    {
        $resident = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $this->request(false, $resident)->assertForbidden();
        $this->restart($resident)->assertForbidden();
        $inactive = User::factory()->create(['role' => UserRole::Admin, 'is_active' => false]);
        $this->assertNotSame(200, $this->request(false, $inactive)->status());
        $this->assertDatabaseCount('iot_device_commands', 0);
        auth()->logout();
        $this->postJson(route('admin.iot-dashboard.scanner', $this->device->id), ['enabled' => false])->assertUnauthorized();
        $this->actingAs($this->admin)->postJson(route('admin.iot-dashboard.restart', $this->device->id), [])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson(route('admin.iot-dashboard.scanner', $this->device->id), ['enabled' => 'off'])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson('/admin/iot-dashboard/devices/'.Str::uuid().'/scanner', ['enabled' => false])->assertNotFound();
        $this->device->forceFill(['revoked_at' => now()])->save();
        $this->request(false)->assertStatus(409);
    }

    public function test_older_firmware_without_control_support_is_reported_as_unsupported(): void
    {
        $this->heartbeat();
        $this->request(false)->assertOk();
        $this->heartbeat();
        $report = $this->report();
        $this->assertFalse($report['supported']);
        $this->assertNull($report['actual']);
        $this->assertSame('delivered', $report['commands'][0]['status']);
    }

    public function test_control_telemetry_is_validated(): void
    {
        $body = ['boot_id' => self::BOOT, 'captured_at_ms' => (int) now()->getTimestampMs(), 'camera_state' => 'ready',
            'telemetry' => ['scanner_enabled' => 2]];
        $this->api('/api/iot/v1/status', $body)->assertStatus(422);
        $body['telemetry'] = ['channel' => 99];
        $this->api('/api/iot/v1/status', $body)->assertStatus(422);
        $this->assertDatabaseCount('iot_device_commands', 0);
        $this->assertInstanceOf(IotAutomaticScanner::class, app(IotAutomaticScanner::class));
    }
}
