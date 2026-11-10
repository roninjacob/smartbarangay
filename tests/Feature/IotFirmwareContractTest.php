<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\IotDevice;
use App\Models\IotScannerPairing;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\CheckInReservations;
use App\Services\IotScannerSessions;
use App\Services\QrTicketIssuer;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IotFirmwareContractTest extends TestCase
{
    public function test_native_firmware_vectors_pair_and_verify_through_real_laravel_endpoints(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'session.driver' => 'database', 'cache.default' => 'array', 'iot.enabled' => true]);
        DB::purge('sqlite');
        $this->assertSame('testing', app()->environment());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->travelTo(Carbon::createFromTimestampUTC(1794000000));
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $resident = User::factory()->create(['role' => UserRole::Resident, 'is_active' => true]);
        $device = new IotDevice;
        $device->forceFill(['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Synthetic firmware contract',
            'credential' => str_repeat('a', 64), 'credential_version' => 1])->save();
        $store = new Store('firmware-test', new ArraySessionHandler(120));
        $store->start();
        DB::table('sessions')->insert(['id' => $store->getId(), 'user_id' => $admin->id,
            'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
        $browser = Request::create('/admin/iot-scanner/poll', 'POST');
        $browser->setLaravelSession($store);
        $browser->setUserResolver(fn () => $admin);
        $scanners = app(IotScannerSessions::class);
        $scanners->challenge($browser, $device->id);
        // Fix only synthetic test identities to match the independent C++/Python fixture.
        IotScannerPairing::first()->forceFill(['id' => '22222222-2222-4222-8222-222222222222',
            'challenge_hash' => hash('sha256', 'SBP1:'.str_repeat('a', 64))])->save();
        $service = Service::create(['name' => 'Synthetic firmware clearance']);
        $schedule = Schedule::create(['date' => now('Asia/Manila')->addDay()->toDateString(), 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 1]);
        $reservation = $resident->reservations()->create(['service_id' => $service->id, 'schedule_id' => $schedule->id,
            'status' => ReservationStatus::ReadyForPickup]);
        $ticket = app(QrTicketIssuer::class)->issue($reservation);
        $ticket->forceFill(['qr_payload' => '550e8400-e29b-41d4-a716-446655440000', 'ticket_code' => '550e8400-e29b-41d4-a716-446655440000'])->save();
        $vectors = json_decode(file_get_contents(base_path('firmware/SmartBarangay-ESP32-CAM-Test/test/iot04-vectors.json')), true, 8, JSON_THROW_ON_ERROR);
        foreach ($vectors as $vector) {
            $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'off', 'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SB_DEVICE' => $device->id, 'HTTP_X_SB_KEY_VERSION' => '1',
                'HTTP_X_SB_TIMESTAMP' => '1794000000', 'HTTP_X_SB_NONCE' => $vector['nonce'], 'HTTP_X_SB_SIGNATURE' => $vector['signature']];
            $response = $this->call('POST', $vector['path'], [], [], [], $server, $vector['body'])->assertOk()->assertJsonPath('ok', true);
            $this->assertStringNotContainsString($ticket->qr_payload, $response->getContent());
        }
        $result = $scanners->poll($browser)['result'];
        $this->assertTrue($result['valid']);
        $this->assertSame(route('admin.reservations.show', $reservation), $result['reservation_url']);
        $this->assertSame($reservation->id, $store->get(CheckInReservations::SESSION_KEY)['reservation_id']);
        $this->assertDatabaseCount('checkin_logs', 0);
        $this->assertDatabaseCount('reservation_payments', 0);
        $this->assertDatabaseCount('reservation_status_histories', 0);
        $this->assertSame(ReservationStatus::ReadyForPickup, $reservation->fresh()->status);
        $this->assertNull($ticket->fresh()->used_at);
        $this->assertNull($scanners->poll($browser)['result']);
    }
}
