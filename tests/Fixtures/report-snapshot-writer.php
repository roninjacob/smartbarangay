<?php

use App\Http\Controllers\Admin\ReservationController;
use App\Http\Requests\Admin\UpdateReservationStatusRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Services\CertificatePreparationState;
use App\Services\CheckInReservations;
use App\Services\QrTicketIssuer;
use App\Services\ReservationPayments;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Tests\Fixtures\CapacityMariaDb;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
CapacityMariaDb::configure((int) $input['port']);
Carbon::setTestNow(Carbon::parse($input['clock'], 'UTC'));
$admin = User::findOrFail($input['admin']);
$record = Reservation::findOrFail($input['reservation']);
try {
    if ($input['operation'] === 'payment') {
        app(ReservationPayments::class)->record($record, $admin);
    } elseif ($input['operation'] === 'checkin') {
        $service = app(CheckInReservations::class);
        $service->record($record, $admin, $service->evidence($record->qrTicket, $admin));
    } elseif ($input['operation'] === 'complete') {
        $data = ['status' => 'completed', 'expected_status' => 'ready_for_pickup'];
        $request = UpdateReservationStatusRequest::create('/admin/reservations/'.$record->id.'/status', 'PATCH', $data);
        $request->setValidator(Validator::make($data, ['status' => 'required', 'expected_status' => 'required']));
        $app->instance('request', $request);
        $request->setUserResolver(fn () => $admin);
        app(ReservationController::class)->updateStatus($request, $record, app(QrTicketIssuer::class), app(CertificatePreparationState::class));
    } else {
        exit(2);
    }
    echo 'accepted';
} catch (Throwable $exception) {
    echo json_encode(['type' => get_class($exception), 'file' => basename($exception->getFile()), 'line' => $exception->getLine()]);
    exit(1);
}
