<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveScheduleRequest;
use App\Http\Requests\Admin\UpdateScheduleStatusRequest;
use App\Models\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function index(): View
    {
        return view('admin.schedules.index', [
            ...$this->shellData('Schedules & Slots'),
            'schedules' => Schedule::withExists('reservations')->withCount('occupiedReservations')->orderBy('date')->orderBy('start_time')->orderBy('id')->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.schedules.form', [...$this->shellData('Create Schedule'), 'schedule' => new Schedule]);
    }

    public function store(SaveScheduleRequest $request): RedirectResponse
    {
        try {
            Schedule::create([...$request->scheduleData(), 'is_active' => true]);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['date' => 'A schedule with this date and time range already exists.']);
        }

        return to_route('admin.schedules.index')->with('status', 'Schedule created successfully.');
    }

    public function edit(Schedule $schedule): View
    {
        return view('admin.schedules.form', [...$this->shellData('Edit Schedule'), 'schedule' => $schedule]);
    }

    public function update(SaveScheduleRequest $request, Schedule $schedule): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $schedule) {
                $locked = Schedule::lockForUpdate()->findOrFail($schedule->id);
                if ($request->integer('capacity') < $locked->lockedOccupancy()) {
                    throw ValidationException::withMessages(['capacity' => 'Capacity cannot be lower than the number of occupying reservations.']);
                }
                $locked->update($request->scheduleData());
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['date' => 'A schedule with this date and time range already exists.']);
        }

        return to_route('admin.schedules.index')->with('status', 'Schedule updated successfully.');
    }

    public function updateStatus(UpdateScheduleStatusRequest $request, Schedule $schedule): RedirectResponse
    {
        $schedule->update(['is_active' => $request->boolean('is_active')]);

        return to_route('admin.schedules.index')->with('status', $schedule->is_active ? 'Schedule activated.' : 'Schedule deactivated. Existing records are preserved.');
    }

    public function destroy(Schedule $schedule): RedirectResponse
    {
        $deleted = DB::transaction(function () use ($schedule): bool {
            $locked = Schedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($locked->reservations()->exists()) {
                return false;
            }
            $locked->delete();

            return true;
        });
        $redirect = to_route('admin.schedules.index');

        return $deleted
            ? $redirect->with('status', 'Unused schedule deleted successfully.')
            : $redirect->withErrors(['schedule' => 'This schedule is linked to an existing reservation and cannot be deleted. You can deactivate it instead.']);
    }

    private function shellData(string $heading): array
    {
        return [
            'roleLabel' => 'Admin', 'navigation' => config('navigation.admin'), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => $heading, 'pageHeading' => $heading, 'pageSection' => 'Schedules & Slots',
        ];
    }
}
