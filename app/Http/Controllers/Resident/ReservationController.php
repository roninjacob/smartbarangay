<?php

namespace App\Http\Controllers\Resident;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Resident\ReservationWizardRequest;
use App\Models\Schedule;
use App\Models\Service;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReservationController extends Controller
{
    private const SESSION_KEY = 'reservation_wizard';

    public function create(Request $request): View
    {
        $draft = $request->session()->get(self::SESSION_KEY, []);
        if (($draft['resident_id'] ?? null) !== $request->user()->id) {
            $request->session()->forget(self::SESSION_KEY);
            $draft = [];
        }

        return $this->page(1, ['services' => Service::where('is_active', true)->orderBy('name')->get(), 'draft' => $draft]);
    }

    public function selectService(ReservationWizardRequest $request): RedirectResponse
    {
        $request->session()->put(self::SESSION_KEY, [
            'resident_id' => $request->user()->id,
            'service_id' => (int) $request->validated('service_id'),
        ]);

        return to_route('resident.reservations.requirements');
    }

    public function requirements(Request $request): View
    {
        $draft = $this->draft($request);

        return $this->page(2, ['service' => $this->service($request, $draft)]);
    }

    public function reviewRequirements(ReservationWizardRequest $request): RedirectResponse
    {
        $draft = $this->draft($request);
        $this->service($request, $draft);
        $draft['requirements_reviewed'] = true;
        $request->session()->put(self::SESSION_KEY, $draft);

        return to_route('resident.reservations.schedule');
    }

    public function schedule(Request $request): View
    {
        $draft = $this->draft($request, true);

        return $this->page(3, [
            'service' => $this->service($request, $draft), 'draft' => $draft,
            'schedules' => Schedule::where('is_active', true)->orderBy('date')->orderBy('start_time')->get(),
        ]);
    }

    public function selectSchedule(ReservationWizardRequest $request): RedirectResponse
    {
        $draft = $this->draft($request, true);
        $this->service($request, $draft);
        $draft['schedule_id'] = (int) $request->validated('schedule_id');
        $draft['confirmation_token'] = (string) Str::uuid();
        $request->session()->put(self::SESSION_KEY, $draft);

        return to_route('resident.reservations.confirm');
    }

    public function confirm(Request $request): View
    {
        $draft = $this->draft($request, true);

        return $this->page(4, [
            'service' => $this->service($request, $draft),
            'schedule' => $this->selectedSchedule($draft), 'draft' => $draft,
        ]);
    }

    public function store(ReservationWizardRequest $request): RedirectResponse
    {
        $draft = $this->draft($request, true);
        if (! isset($draft['schedule_id'], $draft['confirmation_token'])) {
            $this->restartAt('schedule', 'Please choose a schedule before confirming.');
        }
        if (! hash_equals($draft['confirmation_token'], $request->validated('confirmation_token'))) {
            throw ValidationException::withMessages(['confirmation_token' => 'This confirmation has expired. Review your current selection and try again.']);
        }

        DB::transaction(function () use ($request, $draft) {
            // Serialize final checks against Admin edits to these same records.
            $service = Service::lockForUpdate()->find($draft['service_id']);
            $schedule = Schedule::lockForUpdate()->find($draft['schedule_id']);
            if (! $service?->is_active) {
                $request->session()->forget(self::SESSION_KEY);
                $this->restartAt('create', 'The selected service is no longer available. Please choose another service.');
            }
            if (! $schedule?->is_active) {
                $this->restartAt('schedule', 'The selected schedule is no longer available. Please choose another schedule.');
            }
            // Parent locks serialize separate sessions; a locking read sees the latest committed reservation.
            $duplicate = $request->user()->reservations()->occupyingSlot()
                ->where('service_id', $service->id)->where('schedule_id', $schedule->id)
                ->lockForUpdate()->first(['id']);
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'reservation' => 'You already have an active reservation for this service and schedule.',
                ]);
            }
            $request->user()->reservations()->create([
                'service_id' => $service->id, 'schedule_id' => $schedule->id,
                'status' => ReservationStatus::Pending,
            ]);
        });
        // Routes lock the session; subsequent submissions cannot consume this draft again.
        $request->session()->forget(self::SESSION_KEY);

        return to_route('resident.home')->with('status', 'Reservation submitted successfully. Your request is Pending.');
    }

    private function draft(Request $request, bool $reviewed = false): array
    {
        $draft = $request->session()->get(self::SESSION_KEY, []);
        if (($draft['resident_id'] ?? null) !== $request->user()->id || ! isset($draft['service_id'])) {
            $request->session()->forget(self::SESSION_KEY);
            $this->restartAt('create', 'Start by choosing a service.');
        }
        if ($reviewed && ! ($draft['requirements_reviewed'] ?? false)) {
            $this->restartAt('requirements', 'Please review the service requirements first.');
        }

        return $draft;
    }

    private function service(Request $request, array $draft): Service
    {
        $service = Service::with('serviceRequirements')->find($draft['service_id']);
        if (! $service?->is_active) {
            $request->session()->forget(self::SESSION_KEY);
            $this->restartAt('create', 'The selected service is no longer available. Please choose another service.');
        }

        return $service;
    }

    private function selectedSchedule(array $draft): Schedule
    {
        $schedule = isset($draft['schedule_id']) ? Schedule::find($draft['schedule_id']) : null;
        if (! $schedule?->is_active) {
            $this->restartAt('schedule', 'Please choose an active schedule.');
        }

        return $schedule;
    }

    private function restartAt(string $step, string $message): never
    {
        throw new HttpResponseException(to_route('resident.reservations.'.$step)->withErrors(['reservation' => $message]));
    }

    private function page(int $step, array $data): View
    {
        return view('resident.reservations.wizard', [
            'roleLabel' => 'Resident', 'navigation' => config('navigation.resident'),
            'dashboardDate' => now('Asia/Manila'), 'pageTitle' => 'New Reservation',
            'pageHeading' => 'New Reservation', 'pageSection' => 'My services', 'step' => $step,
            ...$data,
        ]);
    }
}
