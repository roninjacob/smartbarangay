@extends('layouts.authenticated')
@section('dashboard')
<x-page-intro subtitle="Your document requests and where each one is.">
    <x-slot:actions><a href="{{ route('resident.reservations.create') }}" class="btn btn-primary"><x-app-icon name="plus"/> New Reservation</a></x-slot:actions>
</x-page-intro>
<section class="dashboard-panel resident-reservation-list" aria-labelledby="reservation-list-heading">
    <div class="dashboard-panel-heading reservation-list-heading">
        <h2 id="reservation-list-heading"><span class="panel-icon is-green" aria-hidden="true"><x-app-icon name="document"/></span>Your submitted requests</h2>
        <span class="panel-count">{{ number_format($reservations->total()) }} {{ \Illuminate\Support\Str::plural('request', $reservations->total()) }}</span>
    </div>
    <div class="rv-toolbar">
        <form method="GET" action="{{ route('resident.reservations.index') }}" class="request-filters rv-filter" style="--rv-filter-selects: 2" aria-label="Filter your requests">
            <div><label for="request-search" class="form-label">Find a request</label><input id="request-search" type="search" name="search" class="form-control" maxlength="100" placeholder="Service name or request number" value="{{ $filters['search'] ?? '' }}"></div>
            <div><label for="request-view" class="form-label">Show</label><select id="request-view" name="view" class="form-select">@foreach(['all' => 'All requests', 'active' => 'In progress & pickup', 'history' => 'Completed & closed'] as $key => $label)<option value="{{ $key }}" @selected(($filters['view'] ?? 'all') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="request-status" class="form-label">Status</label><select id="request-status" name="status" class="form-select"><option value="">All statuses</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
            <div class="rv-filter-actions"><button class="btn btn-primary" type="submit">Find requests</button><a href="{{ route('resident.reservations.index') }}" class="btn btn-outline-secondary">Clear filters</a></div>
        </form>
    </div>
    @if($errors->any())<div class="alert alert-danger mx-3 mt-3" role="alert">{{ $errors->first() }}</div>@endif
    @if($reservations->total() === 0)
        <div class="service-empty">
            <span class="service-empty-icon"><x-app-icon name="document"/></span>
            <h3>{{ array_filter($filters) ? 'No matching requests' : 'No reservations yet' }}</h3><p>Adjust the filters, or start a new reservation to request a document.</p>
            <a href="{{ route('resident.reservations.create') }}" class="btn btn-primary">Create your first reservation</a>
        </div>
    @elseif($reservations->isEmpty())
        <div class="service-empty"><h3>No reservations on this page</h3><p>Return to the first page to see your submitted requests.</p><a href="{{ route('resident.reservations.index') }}" class="btn btn-outline-primary">Back to first page</a></div>
    @else
        <div class="rv-list is-grid">
            @foreach($reservations as $reservation)
                <article class="rv-card tone-{{ $reservation->status->value }}" aria-labelledby="request-{{ $reservation->id }}">
                    <div class="rv-card-top"><span class="rv-card-ref">Request #{{ $reservation->id }}</span><x-reservation-status :status="$reservation->status"/></div>
                    <h3 id="request-{{ $reservation->id }}">{{ $reservation->service->name }}</h3>
                    <x-status-stepper :status="$reservation->status" :compact="true"/>
                    <dl class="rv-meta">
                        <div><dt>Schedule date</dt><dd><time datetime="{{ $reservation->appointment->date->toDateString() }}">{{ $reservation->appointment->date->format('M j, Y') }}</time></dd></div>
                        <div><dt>Time</dt><dd>{{ \Illuminate\Support\Carbon::parse($reservation->appointment->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->appointment->end_time)->format('g:i A') }}</dd></div>
                        <div><dt>Submitted</dt><dd><time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y') }}</time></dd></div>
                    </dl>
                    <div class="rv-card-foot"><span></span><a href="{{ route('resident.reservations.show', $reservation) }}" class="panel-link" aria-label="View Details for request #{{ $reservation->id }}">View Details <x-app-icon name="arrow"/></a></div>
                </article>
            @endforeach
        </div>
        <div class="rv-pagination"><span class="app-note">Showing {{ $reservations->firstItem() }}–{{ $reservations->lastItem() }} of {{ $reservations->total() }} reservations</span>{{ $reservations->links('pagination.civic') }}</div>
    @endif
</section>
@endsection
