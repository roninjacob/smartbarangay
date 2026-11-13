<?php

use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Resident\ReservationController;
use App\Http\Requests\Admin\SaveScheduleRequest;
use App\Http\Requests\Admin\UpdateScheduleStatusRequest;
use App\Http\Requests\Resident\ReservationWizardRequest;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Services\RequirementChecklist;
use App\Services\ReservationFileUploads;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\CapacityMariaDb;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$root = CapacityMariaDb::configure((int) $input['port']);
$marker = $input['marker'];
$directory = str_replace('\\', '/', (string) realpath(dirname($marker)));
if (! str_starts_with(strtolower($directory), strtolower($root.'/barriers/')) || basename($marker) !== 'ready-'.$input['actor']) {
    exit(2);
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
DB::connection()->beforeExecuting(function ($sql) use ($marker) {
    if (str_contains($sql, 'from `schedules`') && str_contains($sql, 'for update')) {
        file_put_contents($marker, 'ready');
    }
});
$user = User::findOrFail($input['user']);
$session = new Store('capacity-test', new ArraySessionHandler(120));
$session->start();
if ($input['operation'] === 'book') {
    $service = Service::with('serviceRequirements')->findOrFail($input['service']);
    $token = (string) Str::uuid();
    $session->put('reservation_wizard', [
        'resident_id' => $user->id, 'service_id' => $service->id,
        'schedule_id' => $input['schedule'], 'confirmation_token' => $token,
        'requirements_reviewed' => true, 'reviewed_fee' => $service->fee_amount,
        'requirement_signature' => app(RequirementChecklist::class)->signature($service->id, $service->serviceRequirements),
        'document_signature' => 'none', 'document_answers' => [],
    ]);
    $request = ReservationWizardRequest::create('/resident/reservations', 'POST', ['confirmation_token' => $token]);
    $request->setValidator(Validator::make($request->all(), ['confirmation_token' => ['required', 'uuid']]));
} elseif ($input['operation'] === 'disable') {
    $request = UpdateScheduleStatusRequest::create('/admin/schedules/'.$input['schedule'].'/status', 'PATCH', ['is_active' => false]);
    $request->setValidator(Validator::make($request->all(), ['is_active' => 'required|boolean']));
} else {
    $request = SaveScheduleRequest::create('/admin/schedules/'.$input['schedule'], 'PUT', $input['data']);
    $request->setValidator(Validator::make($input['data'], ['date' => 'required', 'start_time' => 'required',
        'end_time' => 'required', 'capacity' => 'required|integer|min:1']));
}
$request->setLaravelSession($session);
$app->instance('request', $request);
$app->instance('session.store', $session);
app('redirect')->setSession($session);
$request->setUserResolver(fn () => $user);
try {
    if ($input['operation'] === 'book') {
        app(ReservationController::class)->store($request, app(ReservationFileUploads::class));
    } elseif ($input['operation'] === 'disable') {
        app(ScheduleController::class)->updateStatus($request, Schedule::findOrFail($input['schedule']));
    } else {
        app(ScheduleController::class)->update($request, Schedule::findOrFail($input['schedule']));
    }
    echo json_encode(['outcome' => 'accepted']);
} catch (ValidationException $exception) {
    echo json_encode(['outcome' => 'rejected', 'fields' => array_keys($exception->errors())]);
} catch (HttpResponseException) {
    echo json_encode(['outcome' => 'rejected']);
} catch (Throwable $exception) {
    // No SQL, bound values, user data or exception messages in worker output.
    echo json_encode(['outcome' => 'unexpected', 'type' => get_class($exception),
        'file' => basename($exception->getFile()), 'line' => $exception->getLine()]);
    exit(1);
}
