<?php

namespace Tests\Feature;

use App\Models\IotDevice;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\IotDeviceAuthentication;
use App\Services\QrTicketIssuer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class IotDeviceApiTest extends TestCase
{
    private string $privateDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'cache.default' => 'array', 'session.driver' => 'array',
            'iot.enabled' => true, 'app.debug' => true]);
        DB::purge('sqlite');
        $this->assertSame('testing', app()->environment());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        Cache::flush();
        $this->privateDirectory = sys_get_temp_dir().'/sb-iot02-'.Str::uuid();
        mkdir($this->privateDirectory, 0700);
        if (PHP_OS_FAMILY === 'Windows') {
            $identity = new Process(['whoami', '/user', '/fo', 'csv', '/nh']);
            $identity->mustRun();
            preg_match('/S-1-5-[0-9-]+/', $identity->getOutput(), $match);
            (new Process(['icacls', $this->privateDirectory, '/inheritance:r', '/grant:r', '*'.$match[0].':(OI)(CI)F', '*S-1-5-18:(OI)(CI)F']))->mustRun();
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->privateDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->privateDirectory);
        parent::tearDown();
    }

    private function device(): array
    {
        $key = str_repeat('ab', 32);
        $device = new IotDevice;
        $device->forceFill(['id' => (string) Str::uuid(), 'name' => 'Synthetic scanner',
            'credential' => $key, 'credential_version' => 1])->save();

        return [$device, $key];
    }

    private function headers(IotDevice $device, string $key, string $body = '{"message":"probe"}', array $replace = [], string $signedPath = IotDeviceAuthentication::PATH): array
    {
        $headers = array_replace([
            'X-SB-Device' => $device->id, 'X-SB-Key-Version' => (string) $device->credential_version,
            'X-SB-Timestamp' => (string) now()->timestamp, 'X-SB-Nonce' => bin2hex(random_bytes(16)),
        ], $replace);
        $canonical = implode("\n", ['SB-IOT-1', 'POST', $signedPath, $headers['X-SB-Device'],
            $headers['X-SB-Key-Version'], $headers['X-SB-Timestamp'], $headers['X-SB-Nonce'], hash('sha256', $body)]);
        $headers['X-SB-Signature'] = hash_hmac('sha256', $canonical, hex2bin($key));

        return $headers;
    }

    private function sendProbe(array $headers, string $body = '{"message":"probe"}', array $server = [], string $path = IotDeviceAuthentication::PATH)
    {
        $server = array_replace(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'off', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $server);
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $scheme = $server['HTTPS'] === 'on' ? 'https' : 'http';

        return $this->call('POST', $scheme.'://localhost'.$path, [], [], [], $server, $body);
    }

    public function test_valid_signed_probe_is_stateless_and_key_is_encrypted_and_hidden(): void
    {
        [$device, $key] = $this->device();
        $raw = DB::table('iot_devices')->where('id', $device->id)->value('credential');
        $this->assertNotSame($key, $raw);
        $this->assertStringNotContainsString($key, $raw);
        $this->assertArrayNotHasKey('credential', $device->toArray());
        $response = $this->sendProbe($this->headers($device, $key));
        $response->assertOk()->assertJson(['ok' => true, 'api_version' => '1'])
            ->assertJsonStructure(['request_id', 'server_time'])->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertStringNotContainsString($key, $response->getContent());
        $this->assertStringNotContainsString($device->id, $response->getContent());
        $this->assertDatabaseCount('iot_request_nonces', 1);
    }

    public static function invalidAuthentication(): array
    {
        return [
            'expired' => ['X-SB-Timestamp', '-91'], 'future' => ['X-SB-Timestamp', '+91'],
            'unknown' => ['X-SB-Device', '22222222-2222-4222-8222-222222222222'],
            'old version' => ['X-SB-Key-Version', '2'], 'malformed nonce' => ['X-SB-Nonce', 'bad'],
            'uppercase nonce' => ['X-SB-Nonce', str_repeat('A', 32)],
            'header length' => ['X-SB-Nonce', str_repeat('a', 1000)],
            'invalid timestamp' => ['X-SB-Timestamp', '1e999'],
        ];
    }

    #[DataProvider('invalidAuthentication')]
    public function test_invalid_authentication_is_redacted(string $field, string $value): void
    {
        [$device, $key] = $this->device();
        if ($field === 'X-SB-Timestamp' && in_array($value, ['-91', '+91'])) {
            $value = (string) (now()->timestamp + (int) $value);
        }
        $this->sendProbe($this->headers($device, $key, replace: [$field => $value]))
            ->assertStatus(401)->assertExactJson(['ok' => false, 'error' => 'authentication_failed']);
        $this->assertDatabaseCount('iot_request_nonces', 0);
    }

    public function test_missing_and_wrong_signature_revoked_device_and_corrupt_key_fail_closed(): void
    {
        [$device, $key] = $this->device();
        $headers = $this->headers($device, $key);
        unset($headers['X-SB-Signature']);
        $this->sendProbe($headers)->assertStatus(401);
        $headers['X-SB-Signature'] = str_repeat('0', 64);
        $this->sendProbe($headers)->assertStatus(401);
        $device->forceFill(['revoked_at' => now()])->save();
        $this->sendProbe($this->headers($device, $key))->assertStatus(401);
        DB::table('iot_devices')->where('id', $device->id)->update(['revoked_at' => null, 'credential' => 'corrupt']);
        $this->sendProbe($this->headers($device, $key))->assertStatus(401);
        $this->assertDatabaseCount('iot_request_nonces', 0);
    }

    public function test_exact_body_path_query_and_method_are_bound(): void
    {
        [$device, $key] = $this->device();
        $headers = $this->headers($device, $key);
        $this->sendProbe($headers, '{ "message":"probe"}')->assertStatus(401);
        $this->sendProbe($this->headers($device, $key, signedPath: '/api/iot/v1/other'))->assertStatus(401);
        $this->sendProbe($headers, path: IotDeviceAuthentication::PATH.'?ignored=1')->assertStatus(400);
        $this->getJson(IotDeviceAuthentication::PATH)->assertStatus(405)->assertJsonPath('error', 'method_not_allowed');
        $this->postJson('/api/iot/v1/other', ['message' => 'probe'])->assertNotFound();
        $this->assertDatabaseCount('iot_request_nonces', 0);
    }

    public function test_replay_is_rejected_and_tampering_does_not_burn_nonce(): void
    {
        [$device, $key] = $this->device();
        $headers = $this->headers($device, $key);
        $bad = array_replace($headers, ['X-SB-Signature' => str_repeat('0', 64)]);
        $this->sendProbe($bad)->assertStatus(401);
        $this->sendProbe($headers)->assertOk();
        $this->sendProbe($headers)->assertStatus(401);
        $this->assertDatabaseCount('iot_request_nonces', 1);
    }

    public function test_independent_python_protocol_vector_matches_laravel(): void
    {
        $this->travelTo(Carbon::createFromTimestampUTC(1794000000));
        [$device, $key] = $this->device();
        $device->id = '11111111-1111-4111-8111-111111111111';
        $device->save();
        $headers = [
            'X-SB-Device' => $device->id, 'X-SB-Key-Version' => '1', 'X-SB-Timestamp' => '1794000000',
            'X-SB-Nonce' => '00000000000000000000000000000001',
            'X-SB-Signature' => 'd2bf54105fa7dbb68f3434422965c17cd5ee6ca8c9b3525e67749d20b5fc66d5',
        ];
        $this->sendProbe($headers)->assertOk();
    }

    public static function invalidEnvelopes(): array
    {
        return [
            'malformed' => ['{', [], 400], 'array' => ['[]', [], 422],
            'empty' => ['', [], 400], 'wrong message' => ['{"message":"different"}', [], 422],
            'extra fields' => ['{"message":"probe","reservation_id":1}', [], 422],
            'oversized body' => [str_repeat(' ', 1025), [], 413],
            'oversized declaration' => ['{"message":"probe"}', ['CONTENT_LENGTH' => '1025'], 413],
            'wrong content type' => ['{"message":"probe"}', ['CONTENT_TYPE' => 'text/plain'], 415],
            'compressed body' => ['{"message":"probe"}', ['HTTP_CONTENT_ENCODING' => 'gzip'], 415],
        ];
    }

    #[DataProvider('invalidEnvelopes')]
    public function test_invalid_envelope_never_reaches_authentication(string $body, array $server, int $status): void
    {
        [$device, $key] = $this->device();
        $this->sendProbe($this->headers($device, $key, $body), $body, $server)->assertStatus($status);
        $this->assertDatabaseCount('iot_request_nonces', 0);
    }

    public function test_ip_and_per_device_limits_are_enforced_without_forwarded_ip_bypass(): void
    {
        config(['iot.ip_requests_per_minute' => 2]);
        $this->sendProbe([])->assertStatus(401);
        $this->sendProbe([], server: ['HTTP_X_FORWARDED_FOR' => '10.0.0.2'])->assertStatus(401);
        $this->sendProbe([], server: ['HTTP_X_FORWARDED_FOR' => '10.0.0.3'])->assertStatus(429)->assertJsonPath('error', 'rate_limited');
        Cache::flush();
        config(['iot.ip_requests_per_minute' => 60, 'iot.device_requests_per_minute' => 1]);
        [$device, $key] = $this->device();
        $this->sendProbe($this->headers($device, $key))->assertOk();
        $this->sendProbe($this->headers($device, $key))->assertStatus(429);
    }

    public function test_api_is_disabled_by_default_and_rejects_remote_plaintext_even_with_forwarded_https(): void
    {
        [$device, $key] = $this->device();
        $headers = $this->headers($device, $key);
        config(['iot.enabled' => false]);
        $this->sendProbe($headers)->assertStatus(503);
        config(['iot.enabled' => true]);
        $this->sendProbe($headers, server: ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_PROTO' => 'https'])->assertStatus(403);
        $this->sendProbe($headers, server: ['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => 'on'])->assertOk();
    }

    public function test_provision_duplicate_rotation_revocation_and_private_file_security(): void
    {
        $id = (string) Str::uuid();
        $first = $this->privateDirectory.'/first.json';
        $second = $this->privateDirectory.'/second.json';
        $this->artisan('smartbarangay:iot-device', ['action' => 'provision', 'device' => $id,
            '--name' => 'Synthetic scanner', '--output' => $first])->assertSuccessful();
        $credential = json_decode(file_get_contents($first), true);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $credential['key_hex']);
        $device = IotDevice::findOrFail($id);
        $this->artisan('smartbarangay:iot-device', ['action' => 'provision', 'device' => $id,
            '--name' => 'Duplicate', '--output' => $second])->assertFailed();
        $this->assertFileDoesNotExist($second);
        $this->assertDatabaseCount('iot_devices', 1);
        $oldHeaders = $this->headers($device, $credential['key_hex']);
        $this->artisan('smartbarangay:iot-device', ['action' => 'rotate', 'device' => $id, '--output' => $second])->assertSuccessful();
        $rotated = json_decode(file_get_contents($second), true);
        $this->assertSame(2, $rotated['key_version']);
        $this->assertNotSame($credential['key_hex'], $rotated['key_hex']);
        $this->sendProbe($oldHeaders)->assertStatus(401);
        $device->refresh();
        $this->sendProbe($this->headers($device, $rotated['key_hex']))->assertOk();
        $this->artisan('smartbarangay:iot-device', ['action' => 'revoke', 'device' => $id])->assertSuccessful();
        $this->artisan('smartbarangay:iot-device', ['action' => 'revoke', 'device' => $id])->assertSuccessful();
        $this->sendProbe($this->headers($device, $rotated['key_hex']))->assertStatus(401);
        $this->artisan('smartbarangay:iot-device', ['action' => 'rotate', 'device' => $id,
            '--output' => $this->privateDirectory.'/revoked.json'])->assertFailed();
        $this->assertFileDoesNotExist($this->privateDirectory.'/revoked.json');
        if (PHP_OS_FAMILY === 'Windows') {
            $acl = new Process(['icacls', $first]);
            $acl->mustRun();
            $this->assertStringNotContainsString('(I)', $acl->getOutput());
        } else {
            $this->assertSame(0600, fileperms($first) & 0777);
        }
    }

    public function test_private_export_cannot_overwrite_existing_file_or_write_under_htdocs(): void
    {
        $existing = $this->privateDirectory.'/existing.json';
        file_put_contents($existing, 'preserve');
        foreach ([$existing, base_path('forbidden-device.json')] as $output) {
            $this->artisan('smartbarangay:iot-device', ['action' => 'provision', '--name' => 'Synthetic', '--output' => $output])->assertFailed();
        }
        $this->assertSame('preserve', file_get_contents($existing));
        $this->assertDatabaseCount('iot_devices', 0);
    }

    public function test_future_timestamp_nonce_retention_and_pruning_cover_entire_acceptance_window(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$device, $key] = $this->device();
        $headers = $this->headers($device, $key, replace: ['X-SB-Timestamp' => (string) (now()->timestamp + 90)]);
        $this->sendProbe($headers)->assertOk();
        $this->travel(179)->seconds();
        $this->artisan('smartbarangay:prune-iot-nonces')->assertSuccessful();
        $this->assertDatabaseCount('iot_request_nonces', 1);
        $this->sendProbe($headers)->assertStatus(401);
        $this->travel(2)->seconds();
        $this->artisan('smartbarangay:prune-iot-nonces')->assertSuccessful();
        $this->assertDatabaseCount('iot_request_nonces', 0);
        $this->sendProbe($headers)->assertStatus(401);
    }

    public function test_success_and_failures_preserve_all_existing_business_and_session_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'resident']);
        $service = Service::create(['name' => 'Synthetic IoT probe service', 'fee_amount' => '20.00']);
        $schedule = Schedule::create(['date' => '2026-12-01', 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 10]);
        $reservation = $owner->reservations()->make(['service_id' => $service->id, 'schedule_id' => $schedule->id, 'status' => 'ready_for_pickup']);
        $reservation->forceFill(['fee_snapshot' => '20.00'])->save();
        app(QrTicketIssuer::class)->issue($reservation);
        $reservation->payment()->make()->forceFill(['amount' => '20.00', 'paid_at' => now('UTC'), 'recorded_by' => $admin->id])->save();
        $reservation->checkinLogs()->create(['verification_result' => 'checked_in', 'verified_at' => now('UTC'), 'verified_by' => $admin->id]);
        DB::table('sessions')->insert(['id' => 'synthetic-browser-session', 'payload' => 'synthetic', 'last_activity' => now()->timestamp]);
        $tables = ['users', 'reservations', 'qr_tickets', 'reservation_payments', 'checkin_logs', 'reservation_status_histories', 'sessions'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        [$device, $key] = $this->device();
        $this->sendProbe($this->headers($device, $key))->assertOk();
        $this->sendProbe([])->assertStatus(401);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_backend_failure_is_redacted_even_with_debug_enabled(): void
    {
        Log::spy();
        [$device, $key] = $this->device();
        $this->mock(IotDeviceAuthentication::class)->shouldReceive('authenticate')
            ->andThrow(new \RuntimeException('synthetic-private-detail'));
        $response = $this->sendProbe($this->headers($device, $key));
        $response->assertStatus(503)->assertExactJson(['ok' => false, 'error' => 'service_unavailable']);
        $this->assertStringNotContainsString('synthetic-private-detail', $response->getContent());
        Log::shouldNotHaveReceived('error');
    }

    public function test_human_login_and_cookies_cannot_authenticate_a_device(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->sendProbe([])->assertStatus(401);
        $this->assertDatabaseCount('iot_request_nonces', 0);
    }

    public function test_global_iot_guard_preserves_normal_web_input_and_validation_behavior(): void
    {
        Route::middleware('web')->post('/_test/web-input', function (Request $request) {
            return response()->json(['value' => $request->input('value'), 'raw_length' => strlen($request->getContent())]);
        });
        Route::middleware('web')->post('/_test/web-validation', function (Request $request) {
            $request->validate(['required_value' => ['required']]);
        });
        $value = str_repeat('x', 2048);
        $body = json_encode(['value' => '  '.$value.'  ']);
        $this->call('POST', 'http://localhost/_test/web-input', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertOk()->assertJson(['value' => $value, 'raw_length' => strlen($body)]);
        $this->postJson('/_test/web-validation', [])->assertUnprocessable()->assertJsonValidationErrors('required_value');
    }

    public function test_loopback_plaintext_is_rejected_in_production_environment(): void
    {
        [$device, $key] = $this->device();
        // The synthetic transport request must use the configured production host.
        config(['app.url' => 'http://localhost']);
        app()->instance('env', 'production');
        $headers = $this->headers($device, $key);
        $this->sendProbe($headers)->assertStatus(403);
        $this->assertDatabaseCount('iot_request_nonces', 0);
        $this->sendProbe($headers, server: ['HTTPS' => 'on'])->assertOk();
    }

    public function test_provisioning_console_output_never_contains_generated_credentials(): void
    {
        $path = $this->privateDirectory.'/console.json';
        $code = Artisan::call('smartbarangay:iot-device', ['action' => 'provision', '--name' => 'Synthetic', '--output' => $path]);
        $this->assertSame(0, $code);
        $data = json_decode(file_get_contents($path), true);
        $this->assertStringNotContainsString($data['key_hex'], Artisan::output());
        $this->assertDatabaseCount('iot_devices', 1);
    }

    public function test_provisioning_rejects_a_directory_with_shared_permissions_before_creating_a_file(): void
    {
        $directory = $this->privateDirectory.'/shared';
        mkdir($directory, 0700);
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                (new Process(['icacls', $directory, '/grant', '*S-1-1-0:(OI)(CI)R']))->mustRun();
            } else {
                chmod($directory, 0755);
            }
            $path = $directory.'/blocked.json';
            $this->artisan('smartbarangay:iot-device', ['action' => 'provision', '--name' => 'Synthetic', '--output' => $path])->assertFailed();
            $this->assertFileDoesNotExist($path);
            $this->assertDatabaseCount('iot_devices', 0);
        } finally {
            if (isset($path) && file_exists($path)) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function test_concurrent_duplicate_http_requests_are_accepted_at_most_once(): void
    {
        $database = $this->privateDirectory.'/concurrency.sqlite';
        touch($database);
        config(['database.connections.iot_concurrency' => [
            'driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        $testKey = 'base64:'.base64_encode(str_repeat('k', 32));
        config(['app.key' => $testKey]);
        app()->forgetInstance('encrypter');
        DB::setDefaultConnection('iot_concurrency');
        try {
            (require database_path('migrations/2026_11_07_000030_create_iot_devices_and_request_nonces.php'))->up();
            [$device, $key] = $this->device();
            $headers = $this->headers($device, $key);
        } finally {
            DB::setDefaultConnection('sqlite');
        }
        $input = json_encode(['headers' => $headers, 'start_at' => microtime(true) + 1]);
        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/iot-concurrent-probe.php')], base_path(), [
                'APP_ENV' => 'testing', 'APP_KEY' => $testKey, 'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
                'LOG_CHANNEL' => 'null',
            ], $input, 30);
            $process->start();
            $processes[] = $process;
        }
        $statuses = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $statuses[] = (int) trim($process->getOutput());
        }
        $this->assertSame(1, count(array_filter($statuses, fn ($status) => $status === 200)));
        foreach ($statuses as $status) {
            $this->assertContains($status, [200, 401, 503]);
        }
        $this->assertSame(1, DB::connection('iot_concurrency')->table('iot_request_nonces')->count());
        DB::purge('iot_concurrency');
    }
}
