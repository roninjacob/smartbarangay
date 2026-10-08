@extends('layouts.authenticated')
@section('dashboard')
<section class="dashboard-panel service-catalog" aria-labelledby="schedules-heading">
    <div class="dashboard-panel-heading service-panel-heading">
        <div><h2 id="schedules-heading">Barangay appointment slots</h2><p class="panel-description">Manage appointment dates, time ranges and capacity for Barangay Calayo.</p></div>
        <a class="btn btn-primary service-create-link" href="{{ route('admin.schedules.create') }}"><x-app-icon name="plus"/>Create Schedule</a>
    </div>
    @if($errors->any())<div class="alert alert-danger mx-3" role="alert">{{ $errors->first() }}</div>@endif
    @if($schedules->isEmpty())
        <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="calendar"/></span><h3>No schedules yet</h3><p>Create an appointment slot with a date, time range and capacity.</p><a href="{{ route('admin.schedules.create') }}" class="btn btn-outline-primary">Create your first schedule</a></div>
    @else
        <div class="table-responsive" role="region" aria-label="Appointment schedules table" tabindex="0">
            <table class="table dashboard-table schedule-table align-middle mb-0">
                <caption class="visually-hidden">Barangay Calayo appointment dates, Philippine local times, capacity, status and actions.</caption>
                <thead><tr><th scope="col">Date</th><th scope="col">Time</th><th scope="col">Capacity</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                @foreach($schedules as $schedule)
                    <tr>
                        <th scope="row"><time datetime="{{ $schedule->date->toDateString() }}">{{ $schedule->date->format('M j, Y') }}</time><span class="reservation-reference">{{ $schedule->date->format('l') }}</span></th>
                        <td class="schedule-time">{{ \Illuminate\Support\Carbon::createFromFormat('H:i:s', $schedule->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::createFromFormat('H:i:s', $schedule->end_time)->format('g:i A') }}</td>
                        <td>{{ number_format($schedule->capacity) }} capacity<span class="reservation-reference">{{ $schedule->occupied_reservations_count }} occupied · {{ $schedule->remainingSlots() }} remaining</span></td>
                        <td><span @class(['service-status', 'is-active' => $schedule->is_active])>{{ $schedule->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td><div class="service-actions">
                            <a href="{{ route('admin.schedules.edit', $schedule) }}" class="btn btn-sm btn-outline-primary" aria-label="Edit schedule {{ $schedule->id }}">Edit</a>
                            <form method="POST" action="{{ route('admin.schedules.status', $schedule) }}" data-service-submit>@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $schedule->is_active ? '0' : '1' }}"><button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="{{ $schedule->is_active ? 'Deactivate' : 'Activate' }} schedule {{ $schedule->id }}">{{ $schedule->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                            @if($schedule->reservations_exists)
                                <span class="app-note">In use · deletion unavailable</span>
                            @else
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#delete-schedule-{{ $schedule->id }}" aria-label="Delete schedule {{ $schedule->id }}">Delete</button>
                            @endif
                        </div></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="service-pagination"><span class="app-note">Showing {{ $schedules->firstItem() }}–{{ $schedules->lastItem() }} of {{ $schedules->total() }} schedules</span>{{ $schedules->links('pagination::bootstrap-5') }}</div>
    @endif
</section>
<p class="app-note mt-3">All appointment times use Philippine local time. Deactivation preserves existing records. Only schedules without linked reservations can be deleted.</p>
@foreach($schedules as $schedule)
    @unless($schedule->reservations_exists)
        <div class="modal fade requirement-delete-modal" id="delete-schedule-{{ $schedule->id }}" tabindex="-1" aria-labelledby="delete-schedule-heading-{{ $schedule->id }}" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h2 class="modal-title fs-5" id="delete-schedule-heading-{{ $schedule->id }}">Delete schedule?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close deletion confirmation"></button></div>
            <div class="modal-body"><p>Delete the appointment slot on <strong>{{ $schedule->date->format('M j, Y') }}</strong>, {{ substr($schedule->start_time, 0, 5) }}–{{ substr($schedule->end_time, 0, 5) }}?</p><p class="app-note mb-0">This cannot be undone. Other schedules will remain unchanged.</p></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><form method="POST" action="{{ route('admin.schedules.destroy', $schedule) }}" data-service-submit>@csrf @method('DELETE')<button type="submit" class="btn btn-danger" data-saving-label="Deleting…">Delete Schedule</button></form></div>
        </div></div></div>
    @endunless
@endforeach
@endsection
