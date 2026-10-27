<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveScheduleRequest;
use App\Http\Requests\Admin\UpdateScheduleStatusRequest;
use App\Models\Schedule;
use App\Services\AccountEligibility;
use App\Services\ConfigurationAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'view' => ['sometimes', 'required', 'in:upcoming,past'],
            'status' => ['nullable', 'in:active,inactive'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ], [
            'to.after_or_equal' => 'Through date must be on or after From date.',
            'from.date_format' => 'Choose a valid From date.',
            'to.date_format' => 'Choose a valid Through date.',
        ]);
        $view = $filters['view'] ?? 'upcoming';
        $asOf = now('Asia/Manila');
        $query = Schedule::withExists('reservations')->withCount('occupiedReservations');
        $query->where(function ($query) use ($view, $asOf) {
            $query->whereDate('date', $view === 'past' ? '<' : '>', $asOf->toDateString())
                ->orWhere(fn ($today) => $today->whereDate('date', $asOf->toDateString())
                    ->where('start_time', $view === 'past' ? '<=' : '>', $asOf->format('H:i:s')));
        });
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('date', '<=', $date));
        $direction = $view === 'past' ? 'desc' : 'asc';

        return view('admin.schedules.index', [
            ...$this->shellData('Schedules & Slots'),
            'view' => $view, 'filters' => $filters, 'asOf' => $asOf,
            'returnContext' => [...$filters, 'view' => $view, 'page' => max(1, $request->integer('page', 1))],
            'schedules' => $query->orderBy('date', $direction)->orderBy('start_time', $direction)->orderBy('id', $direction)->paginate(15)->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.schedules.form', [...$this->shellData('Create Schedule'), 'schedule' => new Schedule, 'returnContext' => $this->returnContext($request)]);
    }

    public function store(SaveScheduleRequest $request): RedirectResponse
    {
        $context = $this->returnContext($request);
        try {
            DB::transaction(function () use ($request) {
                app(AccountEligibility::class)->lock($request->user(), UserRole::Admin);
                $candidate = new Schedule($request->scheduleData());
                if (! $candidate->startsInFuture()) {
                    throw ValidationException::withMessages(['date' => 'Choose a future start time in Philippine time.']);
                }
                $schedule = Schedule::create([...$request->scheduleData(), 'is_active' => true]);
                $audit = app(ConfigurationAudit::class);
                $audit->record($request->user(), $schedule, 'created', [], $audit->values($schedule));
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['date' => 'A schedule with this date and time range already exists.']);
        }

        return to_route('admin.schedules.index', $context)->with('status', 'Schedule created successfully.');
    }

    public function edit(Request $request, Schedule $schedule): View
    {
        $schedule->loadExists('reservations');

        return view('admin.schedules.form', [...$this->shellData('Edit Schedule'), 'schedule' => $schedule, 'returnContext' => $this->returnContext($request)]);
    }

    public function update(SaveScheduleRequest $request, Schedule $schedule): RedirectResponse
    {
        $context = $this->returnContext($request);
        try {
            DB::transaction(function () use ($request, $schedule) {
                app(AccountEligibility::class)->lock($request->user(), UserRole::Admin);
                $locked = Schedule::lockForUpdate()->findOrFail($schedule->id);
                $audit = app(ConfigurationAudit::class);
                $audit->requireRevision($locked, $request->input('edit_revision'));
                $old = $audit->values($locked);
                $data = $request->scheduleData();
                if ($locked->reservations()->lockForUpdate()->first(['id'])
                    && ($data['date'] !== $locked->date->toDateString() || $data['start_time'] !== $locked->start_time || $data['end_time'] !== $locked->end_time)) {
                    throw ValidationException::withMessages(['date' => 'Date and time cannot be changed after a reservation is linked. Create another schedule instead.']);
                }
                if ($request->integer('capacity') < $locked->lockedOccupancy()) {
                    throw ValidationException::withMessages(['capacity' => 'Capacity cannot be lower than the number of occupying reservations.']);
                }
                if (($data['date'] !== $locked->date->toDateString() || $data['start_time'] !== $locked->start_time || $data['end_time'] !== $locked->end_time)
                    && ! (new Schedule($data))->startsInFuture()) {
                    throw ValidationException::withMessages(['date' => 'Choose a future start time in Philippine time.']);
                }
                $locked->update($data);
                $audit->record($request->user(), $locked, 'updated', $old, $audit->values($locked));
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['date' => 'A schedule with this date and time range already exists.']);
        }

        return to_route('admin.schedules.index', $context)->with('status', 'Schedule updated successfully.');
    }

    public function updateStatus(UpdateScheduleStatusRequest $request, Schedule $schedule): RedirectResponse
    {
        $context = $this->returnContext($request);
        DB::transaction(function () use ($request, $schedule) {
            app(AccountEligibility::class)->lock($request->user(), UserRole::Admin);
            $locked = Schedule::lockForUpdate()->findOrFail($schedule->id);
            if ($request->boolean('is_active') && ! $locked->startsInFuture()) {
                throw ValidationException::withMessages(['is_active' => 'This slot has already started and cannot be activated. Create a new upcoming schedule instead.']);
            }
            $audit = app(ConfigurationAudit::class);
            $old = $audit->values($locked);
            $locked->update(['is_active' => $request->boolean('is_active')]);
            $audit->record($request->user(), $locked, 'updated', $old, $audit->values($locked));
        });

        return to_route('admin.schedules.index', $context)->with('status', $request->boolean('is_active') ? 'Schedule activated.' : 'Schedule deactivated. Existing records are preserved.');
    }

    public function destroy(Request $request, Schedule $schedule): RedirectResponse
    {
        $context = $this->returnContext($request);
        $deleted = DB::transaction(function () use ($request, $schedule): bool {
            app(AccountEligibility::class)->lock($request->user(), UserRole::Admin);
            $locked = Schedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($locked->reservations()->exists()) {
                return false;
            }
            $audit = app(ConfigurationAudit::class);
            $old = $audit->values($locked);
            $locked->delete();
            $audit->record($request->user(), $locked, 'deleted', $old, []);

            return true;
        });
        $redirect = to_route('admin.schedules.index', $context);

        return $deleted
            ? $redirect->with('status', 'Unused schedule deleted successfully.')
            : $redirect->withErrors(['schedule' => 'This schedule is linked to an existing reservation and cannot be deleted. You can deactivate it instead.']);
    }

    private function returnContext(Request $request): array
    {
        $data = $request->validate([
            'return_filters' => ['sometimes', 'array:view,status,from,to,page'],
            'return_filters.view' => ['sometimes', 'required', 'in:upcoming,past'],
            'return_filters.status' => ['nullable', 'in:active,inactive'],
            'return_filters.from' => ['nullable', 'date_format:Y-m-d'],
            'return_filters.to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('return_filters.from') ? ['after_or_equal:return_filters.from'] : [])],
            'return_filters.page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ]);

        return $data['return_filters'] ?? [];
    }

    private function shellData(string $heading): array
    {
        return [
            'roleLabel' => 'Admin', 'navigation' => config('navigation.admin'), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => $heading, 'pageHeading' => $heading, 'pageSection' => 'Schedules & Slots',
        ];
    }
}
