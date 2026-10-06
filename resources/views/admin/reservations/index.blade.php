@extends('layouts.authenticated')
@section('dashboard')
<section class="dashboard-panel admin-reservation-catalog" aria-labelledby="admin-reservations-heading">
    <div class="dashboard-panel-heading"><div><h2 id="admin-reservations-heading">Barangay reservation requests</h2><p class="panel-description">Review and process service requests from Barangay Calayo residents.</p></div></div>
    @if($errors->any())<div class="alert alert-danger mx-3" role="alert">{{ $errors->first() }}</div>@endif
    <form method="GET" action="{{ route('admin.reservations.index') }}" class="reservation-filters row g-3">
        <div class="col-md-5"><label class="form-label" for="search">Search resident or service</label><input class="form-control" id="search" name="search" type="search" maxlength="255" value="{{ $filters['search'] ?? '' }}" placeholder="Resident name or document"></div>
        <div class="col-md-3"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All statuses</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
        <div class="col-md-4 reservation-filter-actions"><button type="submit" class="btn btn-primary">Apply filters</button><a href="{{ route('admin.reservations.index') }}" class="btn btn-outline-secondary">Clear</a></div>
    </form>
    @if($reservations->isEmpty())
        <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="document"/></span><h3>{{ ($filters['search'] ?? '') === '' && empty($filters['status']) && $reservations->total() === 0 ? 'No reservations yet' : 'No matching reservations on this page' }}</h3><p>Try another search or status, or return to the full reservation list.</p><a href="{{ route('admin.reservations.index') }}" class="btn btn-outline-primary">View all reservations</a></div>
    @else
        <div class="table-responsive" tabindex="0" role="region" aria-label="Reservation requests table">
            <table class="table dashboard-table admin-reservation-table align-middle mb-0">
                <caption class="visually-hidden">Resident requests, schedules, status, submission dates and review actions.</caption>
                <thead><tr><th scope="col">Service / Resident</th><th scope="col">Schedule</th><th scope="col">Status</th><th scope="col">Submitted</th><th scope="col">Action</th></tr></thead>
                <tbody>@foreach($reservations as $reservation)
                    <tr>
                        <th scope="row"><span class="reservation-reference">Request #{{ $reservation->id }}</span><span class="reservation-service">{{ $reservation->service->name }}</span><span class="reservation-list-resident">{{ $reservation->user->name }}</span></th>
                        <td><span class="mobile-field-label" aria-hidden="true">Schedule</span><time datetime="{{ $reservation->schedule->date->toDateString() }}">{{ $reservation->schedule->date->format('M j, Y') }}</time><span class="reservation-reference">{{ \Illuminate\Support\Carbon::parse($reservation->schedule->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->schedule->end_time)->format('g:i A') }}</span></td>
                        <td><span class="mobile-field-label" aria-hidden="true">Status</span><x-reservation-status :status="$reservation->status"/></td>
                        <td><span class="mobile-field-label" aria-hidden="true">Submitted</span><time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y') }}</time></td>
                        <td><a href="{{ route('admin.reservations.show', $reservation) }}" class="btn btn-sm btn-outline-primary reservation-details-link" aria-label="View request #{{ $reservation->id }}">View request</a></td>
                    </tr>
                @endforeach</tbody>
            </table>
        </div>
        <div class="service-pagination"><span class="app-note">Showing {{ $reservations->firstItem() }}–{{ $reservations->lastItem() }} of {{ $reservations->total() }} reservations</span>{{ $reservations->links('pagination::bootstrap-5') }}</div>
    @endif
</section>
@endsection
