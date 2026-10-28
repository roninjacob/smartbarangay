@extends('layouts.authenticated')
@section('dashboard')
<section class="reservation-overview" aria-label="All requests operational overview">
    <article class="reservation-overview-card"><h2>Needs review</h2><div><strong>{{ number_format($overview->needs_review) }}</strong>@if($overview->needs_review > 0)<span class="worklist-attention">Action needed</span>@endif</div><span class="visually-hidden">Pending and Under Review requests, across all dates.</span></article>
    <article class="reservation-overview-card"><h2>Ready for pickup</h2><div><strong>{{ number_format($overview->ready_for_pickup) }}</strong></div><span class="visually-hidden">Current Ready for Pickup requests, across all dates.</span></article>
    <article class="reservation-overview-card"><h2>Completed today</h2><div><strong>{{ number_format($overview->completed_today) }}</strong></div><span class="visually-hidden">Completed transitions recorded in status history today, Philippine time.</span></article>
</section>
<section class="dashboard-panel admin-reservation-catalog reservation-worklist" aria-labelledby="admin-reservations-heading">
    <div class="worklist-heading"><h2 id="admin-reservations-heading">Resident requests</h2><p>Live overview above · Filtered requests below</p></div>
    @if($errors->any())<div class="alert alert-danger mx-3" role="alert">{{ $errors->first() }}</div>@endif
    <nav class="d-flex flex-wrap gap-2 px-3 pb-3" aria-label="Reservation follow-up filters">
        @foreach(['' => 'All follow-ups', 'cancellation' => 'Cancellation requests', 'past_appointment' => 'Past appointment / no arrival', 'missed' => 'Recorded missed appointments'] as $value => $label)
            <a class="btn btn-sm {{ ($filters['followup'] ?? '') === $value ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('admin.reservations.index', [...$filters, 'followup' => $value]) }}" @if(($filters['followup'] ?? '') === $value) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <form method="GET" action="{{ route('admin.reservations.index') }}" class="worklist-filters" data-worklist-filters>
        @if(!empty($filters['followup']))<input type="hidden" name="followup" value="{{ $filters['followup'] }}">@endif
        <div class="worklist-search"><label class="visually-hidden" for="search">Search resident or service</label><button type="submit" class="worklist-search-button" aria-label="Apply filters"><x-app-icon name="search"/></button><input class="form-control" id="search" name="search" type="search" maxlength="255" value="{{ $filters['search'] ?? '' }}" placeholder="Search resident or service…" aria-describedby="worklist-filter-help"></div>
        <div class="worklist-status"><label class="visually-hidden" for="status">Status</label><select class="form-select" id="status" name="status" data-worklist-status><option value="">All statuses</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
        <a href="{{ route('admin.reservations.index') }}" class="btn btn-outline-primary worklist-clear">Clear filters</a>
        <noscript><button type="submit" class="btn btn-primary">Apply filters</button></noscript>
        <p id="worklist-filter-help" class="visually-hidden">Press Enter or use the search button to apply your search. Selecting a status applies the filters automatically when JavaScript is available.</p>
    </form>
    @if($reservations->isEmpty())
        <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="document"/></span><h3>{{ ($filters['search'] ?? '') === '' && empty($filters['status']) && empty($filters['followup']) && $reservations->total() === 0 ? 'No reservations yet' : 'No matching reservations on this page' }}</h3><p>Try another search or status, or return to the full reservation list.</p><a href="{{ route('admin.reservations.index') }}" class="btn btn-outline-primary">View all reservations</a></div>
    @else
        <div class="table-responsive" tabindex="0" role="region" aria-label="Reservation requests table">
            <table class="table dashboard-table reservation-worklist-table align-middle mb-0">
                <caption class="visually-hidden">Resident requests, services, historical appointments, current status and review actions.</caption>
                <thead><tr><th scope="col">Resident / request</th><th scope="col">Service</th><th scope="col">Appointment</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead>
                <tbody>@foreach($reservations as $reservation)
                    <tr>
                        <th scope="row"><div class="worklist-person"><x-resident-avatar :user="$reservation->user" :initials="true"/><div><span class="worklist-person-name">{{ $reservation->user->name }}</span><span class="reservation-reference">Request #{{ $reservation->id }} · Submitted <time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y') }}</time></span></div></div></th>
                        <td><span class="worklist-mobile-label" aria-hidden="true">Service</span><span class="worklist-service">{{ $reservation->service->name }}</span></td>
                        <td><span class="worklist-mobile-label" aria-hidden="true">Appointment</span><time datetime="{{ $reservation->appointment->date->toDateString() }}">{{ $reservation->appointment->date->format('M j, Y') }}</time><span class="reservation-reference">{{ \Illuminate\Support\Carbon::parse($reservation->appointment->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->appointment->end_time)->format('g:i A') }}</span></td>
                        <td><span class="worklist-mobile-label" aria-hidden="true">Status</span><x-reservation-status :status="$reservation->status"/></td>
                        <td><a href="{{ route('admin.reservations.show', $reservation) }}" class="btn btn-outline-primary worklist-view" aria-label="View request #{{ $reservation->id }}">View request <span aria-hidden="true">↗</span></a></td>
                    </tr>
                @endforeach</tbody>
            </table>
        </div>
        <div class="service-pagination"><span class="app-note">Showing {{ $reservations->firstItem() }}–{{ $reservations->lastItem() }} of {{ $reservations->total() }} reservations</span>{{ $reservations->links('pagination.civic') }}</div>
    @endif
</section>
@endsection
