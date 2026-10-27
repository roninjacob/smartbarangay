@extends('layouts.authenticated')
@section('dashboard')
<section class="dashboard-panel service-catalog schedule-catalog" aria-labelledby="schedules-heading">
    <div class="dashboard-panel-heading service-panel-heading">
        <div><h2 id="schedules-heading">Barangay appointment slots</h2><p class="panel-description">Plan upcoming appointments and keep past schedules easy to find.</p></div>
        <a class="btn btn-primary service-create-link" href="{{ route('admin.schedules.create', ['return_filters' => $returnContext]) }}"><x-app-icon name="plus"/>Create Schedule</a>
    </div>
    <nav class="schedule-tabs" aria-label="Schedule views">
        @foreach(['upcoming' => 'Upcoming', 'past' => 'Past Schedules'] as $key => $label)
            <a href="{{ route('admin.schedules.index', ['view' => $key]) }}" @class(['schedule-tab', 'is-selected' => $view === $key]) @if($view === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <div class="schedule-workspace">
        <div class="schedule-context"><span class="schedule-context-icon"><x-app-icon name="calendar"/></span><div><h3>{{ $view === 'past' ? 'Past appointments, preserved' : 'Your upcoming appointment slots' }}</h3><p>{{ $view === 'past' ? 'Slots that have already started appear here. Reservation history stays intact; processing can continue.' : 'Future slots appear here, with the nearest date first. Only active slots with remaining capacity can be booked.' }}</p></div></div>
        <form method="GET" action="{{ route('admin.schedules.index') }}" class="schedule-filters">
            <input type="hidden" name="view" value="{{ $view }}">
            <div><label for="schedule-status" class="form-label">Booking status</label><select id="schedule-status" name="status" class="form-select"><option value="">All statuses</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option></select></div>
            <div><label for="schedule-from" class="form-label">From date</label><input id="schedule-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control"></div>
            <div><label for="schedule-to" class="form-label">Through date</label><input id="schedule-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control"></div>
            <div class="schedule-filter-actions"><button class="btn btn-primary" type="submit">Apply filters</button><a href="{{ route('admin.schedules.index', ['view' => $view]) }}" class="btn btn-outline-secondary">Reset</a></div>
        </form>
        <p class="app-note mb-0">Philippine time &middot; {{ $asOf->format('M j, Y') }} &middot; {{ $asOf->format('g:i A') }} &middot; {{ number_format($schedules->total()) }} {{ $schedules->total() === 1 ? 'slot' : 'slots' }} in this view</p>
    </div>
    @if($errors->any())<div class="alert alert-danger mx-3" role="alert">{{ $errors->first() }}</div>@endif
    @if($schedules->isEmpty())
        <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="calendar"/></span><h3>{{ $schedules->total() > 0 ? 'No slots on this page' : ($view === 'past' ? 'No past schedules found' : 'No upcoming schedules found') }}</h3>@if($schedules->total() > 0)<a class="btn btn-primary" href="{{ route('admin.schedules.index', $filters) }}">Back to first page</a>@endif<p>Try adjusting the filters{{ $view === 'upcoming' ? ' or create a new appointment slot.' : '. Past schedules will appear here once their start time is reached.' }}</p><a href="{{ route('admin.schedules.index', ['view' => $view]) }}" class="btn btn-outline-primary">Clear filters</a></div>
    @else
        <div class="table-responsive" role="region" aria-label="Appointment schedules table" tabindex="0">
            <table class="table dashboard-table schedule-table align-middle mb-0">
                <caption class="visually-hidden">Barangay Calayo appointment dates, Philippine local times, capacity, status and actions.</caption>
                <thead><tr><th scope="col">Date</th><th scope="col">Time</th><th scope="col">Capacity</th><th scope="col">Status</th><th scope="col">Manage slot</th></tr></thead>
                <tbody>
                @foreach($schedules as $schedule)
                    <tr>
                        <th scope="row" data-label="Date"><time datetime="{{ $schedule->date->toDateString() }}">{{ $schedule->date->format('M j, Y') }}</time><span class="reservation-reference">{{ $schedule->date->format('l') }}</span></th>
                        <td class="schedule-time" data-label="Time">{{ \Illuminate\Support\Carbon::createFromFormat('H:i:s', $schedule->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::createFromFormat('H:i:s', $schedule->end_time)->format('g:i A') }}</td>
                        <td data-label="Capacity">
                            <strong>{{ number_format($schedule->capacity) }} slots</strong>
                            <span class="reservation-reference">
                                @if($view === 'past')
                                    {{ $schedule->occupied_reservations_count }} in-progress {{ $schedule->occupied_reservations_count === 1 ? 'request' : 'requests' }}
                                @else
                                    {{ $schedule->occupied_reservations_count }} occupied &middot; {{ $schedule->remainingSlots() }} remaining
                                @endif
                            </span>
                        </td>
                        <td data-label="Booking status"><span @class(['service-status', 'is-active' => $schedule->is_active])>{{ $schedule->is_active ? 'Active' : 'Inactive' }}</span>@if($view === 'past')<span class="schedule-past-label">Past &middot; booking closed</span>@endif</td>
                        <td data-label="Manage slot"><div class="schedule-row-actions">
                            <a href="{{ route('admin.schedules.edit', ['schedule' => $schedule, 'return_filters' => $returnContext]) }}" class="btn btn-sm btn-outline-primary" aria-label="Edit schedule {{ $schedule->id }}">Edit</a>
                            @if($view !== 'past' || $schedule->is_active)
                            <form method="POST" action="{{ route('admin.schedules.status', $schedule) }}" data-service-submit>@csrf @include('admin.schedules.return-context') @method('PATCH')<input type="hidden" name="is_active" value="{{ $schedule->is_active ? '0' : '1' }}"><button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="{{ $schedule->is_active ? 'Deactivate' : 'Activate' }} schedule {{ $schedule->id }}">{{ $schedule->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                            @endif
                            @if($schedule->reservations_exists)
                                <span class="schedule-history-note">Has reservation history &mdash; cannot delete</span>
                            @else
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-schedule-{{ $schedule->id }}" aria-label="Delete schedule {{ $schedule->id }}">Delete</button>
                            @endif
                        </div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="service-pagination"><span class="app-note">Showing {{ $schedules->firstItem() }}–{{ $schedules->lastItem() }} of {{ $schedules->total() }} schedules</span>{{ $schedules->links('pagination.civic') }}</div>
    @endif
</section>
<p class="app-note mt-3">Past schedules are kept for reference. Moving a slot to the past does not complete or cancel its reservations. Only unused slots can be deleted.</p>
@foreach($schedules as $schedule)
    @unless($schedule->reservations_exists)
        <div class="modal fade requirement-delete-modal" id="delete-schedule-{{ $schedule->id }}" tabindex="-1" aria-labelledby="delete-schedule-heading-{{ $schedule->id }}" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h2 class="modal-title fs-5" id="delete-schedule-heading-{{ $schedule->id }}">Delete schedule?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close deletion confirmation"></button></div>
            <div class="modal-body"><p>Delete the appointment slot on <strong>{{ $schedule->date->format('M j, Y') }}</strong>, {{ substr($schedule->start_time, 0, 5) }}–{{ substr($schedule->end_time, 0, 5) }}?</p><p class="app-note mb-0">This cannot be undone. Other schedules will remain unchanged.</p></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><form method="POST" action="{{ route('admin.schedules.destroy', $schedule) }}" data-service-submit>@csrf @include('admin.schedules.return-context') @method('DELETE')<button type="submit" class="btn btn-danger" data-saving-label="Deleting…">Delete Schedule</button></form></div>
        </div></div></div>
    @endunless
@endforeach
@endsection
