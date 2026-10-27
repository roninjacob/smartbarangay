@extends('layouts.authenticated')
@section('dashboard')
<section class="dashboard-panel resident-reservation-list" aria-labelledby="reservation-list-heading">
    <div class="dashboard-panel-heading reservation-list-heading">
        <div><h2 id="reservation-list-heading">Your submitted requests</h2><p class="panel-description">Review your service requests and their current status.</p></div>
        <a href="{{ route('resident.reservations.create') }}" class="btn btn-primary">New Reservation</a>
    </div>
    <form method="GET" action="{{ route('resident.reservations.index') }}" class="request-filters p-4 border-top" aria-label="Filter your requests">
        <div class="row g-3 align-items-end">
            <div class="col-lg-5"><label for="request-search" class="form-label">Find a request</label><input id="request-search" type="search" name="search" class="form-control" maxlength="100" placeholder="Service name or request number" value="{{ $filters['search'] ?? '' }}"></div>
            <div class="col-sm-6 col-lg-3"><label for="request-view" class="form-label">Show</label><select id="request-view" name="view" class="form-select">@foreach(['all' => 'All requests', 'active' => 'In progress & pickup', 'history' => 'Completed & closed'] as $key => $label)<option value="{{ $key }}" @selected(($filters['view'] ?? 'all') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-sm-6 col-lg-4"><label for="request-status" class="form-label">Status</label><select id="request-status" name="status" class="form-select"><option value="">All statuses</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
            <div class="col-12 d-flex flex-wrap gap-3"><button class="btn btn-primary" type="submit">Find requests</button><a href="{{ route('resident.reservations.index') }}" class="btn btn-outline-secondary">Clear filters</a></div>
        </div>
    </form>
    @if($errors->any())<div class="alert alert-danger mx-4" role="alert">{{ $errors->first() }}</div>@endif
    @if($reservations->total() === 0)
        <div class="service-empty">
            <span class="service-empty-icon"><x-app-icon name="document"/></span>
            <h3>{{ array_filter($filters) ? 'No matching requests' : 'No reservations yet' }}</h3><p>Adjust the filters or start a new reservation to request a document.</p>
            <a href="{{ route('resident.reservations.create') }}" class="btn btn-outline-primary">Create your first reservation</a>
        </div>
    @elseif($reservations->isEmpty())
        <div class="service-empty"><h3>No reservations on this page</h3><p>Return to the first page to see your submitted requests.</p><a href="{{ route('resident.reservations.index') }}" class="btn btn-outline-primary">Back to first page</a></div>
    @else
        @foreach($reservations as $reservation)
            <article class="resident-reservation-row" aria-labelledby="request-{{ $reservation->id }}">
                <div class="reservation-row-service">
                    <span class="reservation-reference">Request #{{ $reservation->id }}</span>
                    <h3 id="request-{{ $reservation->id }}">{{ $reservation->service->name }}</h3>
                    <x-reservation-status :status="$reservation->status"/>
                </div>
                <dl class="reservation-row-facts">
                    <div><dt>Schedule date</dt><dd><time datetime="{{ $reservation->appointment->date->toDateString() }}">{{ $reservation->appointment->date->format('M j, Y') }}</time></dd></div>
                    <div><dt>Time</dt><dd>{{ \Illuminate\Support\Carbon::parse($reservation->appointment->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->appointment->end_time)->format('g:i A') }}</dd></div>
                    <div><dt>Submitted</dt><dd><time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y') }}</time></dd></div>
                </dl>
                <a href="{{ route('resident.reservations.show', $reservation) }}" class="btn btn-outline-primary reservation-details-link" aria-label="View Details for request #{{ $reservation->id }}">View Details</a>
            </article>
        @endforeach
        <div class="service-pagination"><span class="app-note">Showing {{ $reservations->firstItem() }}–{{ $reservations->lastItem() }} of {{ $reservations->total() }} reservations</span>{{ $reservations->links('pagination.civic') }}</div>
    @endif
</section>
@endsection
