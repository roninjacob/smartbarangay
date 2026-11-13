<?php

use App\Http\Controllers\Admin\ReservationController;
use App\Http\Requests\Admin\UpdateReservationStatusRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Services\CertificatePreparationState;
use App\Services\CheckInReservations;
use App\Services\QrTicketIssuer;
use App\Services\ReservationFollowups;
use App\Services\ReservationPayments;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\CapacityMariaDb;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = CapacityMariaDb::configure((int) $input['port']);
$directory = str_replace('\\', '/', (string) realpath(dirname($input['marker'])));
if (! str_starts_with(strtolower($directory), strtolower($root.'/barriers/'))
    || basename($input['marker']) !== 'operational-'.$input['actor']) {
    exit(2);
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
DB::connection()->beforeExecuting(function ($sql) use ($input) {
    if (str_contains($sql, 'from `reservations`') && str_contains($sql, 'for update')) {
        file_put_contents($input['marker'], 'ready');
    }
});
$admin = User::findOrFail($input['admin']);
$reservation = Reservation::findOrFail($input['reservation']);
try {
    switch ($input['operation']) {
        case 'payment':
            $result = app(ReservationPayments::class)->record($reservation, $admin);
            break;
        case 'checkin':
            // Synthetic trusted evidence; HTTP/session authorization has separate feature coverage.
            $checkins = app(CheckInReservations::class);
            $result = $checkins->record($reservation, $admin, $checkins->evidence($reservation->qrTicket, $admin));
            break;
        case 'cancel':
            app(ReservationFollowups::class)->reviewCancellation($admin, $reservation->id, $input['cancellation'],
                'approved', 'Synthetic cancellation decision.', 'ready_for_pickup');
            $result = null;
            break;
        case 'status':
            $data = ['status' => $input['next'], 'expected_status' => $input['expected'] ?? 'under_review',
                'resident_message' => $input['next'] === 'rejected' ? 'Synthetic rejection.' : null,
                'notes' => 'Synthetic review.'];
            $request = UpdateReservationStatusRequest::create('/admin/reservations/'.$reservation->id.'/status', 'PATCH', $data);
            $request->setValidator(Validator::make($data, ['status' => 'required', 'expected_status' => 'required',
                'notes' => 'nullable', 'resident_message' => 'nullable']));
            $app->instance('request', $request);
            $request->setUserResolver(fn () => $admin);
            app(ReservationController::class)->updateStatus($request, $reservation,
                app(QrTicketIssuer::class), app(CertificatePreparationState::class));
            $result = null;
            break;
        default:
            exit(2);
    }
    echo json_encode(['outcome' => 'accepted', 'id' => $result?->id]);
} catch (ValidationException $exception) {
    echo json_encode(['outcome' => 'rejected', 'fields' => array_keys($exception->errors())]);
} catch (Throwable $exception) {
    echo json_encode(['outcome' => 'unexpected', 'type' => get_class($exception),
        'file' => basename($exception->getFile()), 'line' => $exception->getLine()]);
    exit(1);
}
