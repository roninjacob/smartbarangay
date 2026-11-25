@extends('layouts.authenticated')
@section('dashboard')
<x-page-intro subtitle="Review resident requests, process their status and follow up on appointments."/>

<section class="rv-overview" aria-label="All requests overview">
    <x-dashboard-stat label="Needs review" :value="$overview->needs_review" icon="status" tone="gold" :note="$overview->needs_review > 0 ? 'Pending and Under Review' : ''"/>
    <x-dashboard-stat label="Ready for pickup" :value="$overview->ready_for_pickup" icon="services" tone="teal"/>
    <x-dashboard-stat label="Completed today" :value="$overview->completed_today" icon="check" tone="indigo" note="Philippine time"/>
</section>

<section class="dashboard-panel rv-worklist" aria-labelledby="admin-reservations-heading">
    <div class="dashboard-panel-heading">
        <h2 id="admin-reservations-heading"><span class="panel-icon" aria-hidden="true"><x-app-icon name="document"/></span>Resident requests</h2>
        <span class="panel-count">{{ number_format($reservations->total()) }} {{ \Illuminate\Support\Str::plural('request', $reservations->total()) }}</span>
    </div>
    <div class="rv-toolbar">
        @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
        <form method="GET" action="{{ route('admin.reservations.index') }}" class="rv-filter rv-request-search" data-worklist-filters>
            <div>
                <label class="form-label" for="request-status">Status</label>
                <select class="form-select" id="request-status" name="status">
                    <option value="">All statuses</option>
                    @foreach($statuses as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label" for="request-followup">Follow-up</label>
                <select class="form-select" id="request-followup" name="followup">
                    <option value="">All follow-ups</option>
                    @foreach(['cancellation' => 'Cancellation requests', 'past_appointment' => 'Past appointment / no arrival', 'missed' => 'Recorded missed appointments'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['followup'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div><label class="form-label" for="search">Search</label><input class="form-control" id="search" name="search" type="search" maxlength="255" value="{{ $filters['search'] ?? '' }}" placeholder="Resident name or service"></div>
            <div class="rv-filter-actions"><button type="submit" class="btn btn-primary"><x-app-icon name="search"/> Search</button><a href="{{ route('admin.reservations.index') }}" class="btn btn-outline-secondary">Clear filters</a></div>
        </form>
    </div>

    @if($reservations->isEmpty())
        <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="document"/></span><h3>{{ ($filters['search'] ?? '') === '' && empty($filters['status']) && empty($filters['followup']) && $reservations->total() === 0 ? 'No reservations yet' : 'No matching reservations on this page' }}</h3><p>Try another search or status, or return to the full list.</p><a href="{{ route('admin.reservations.index') }}" class="btn btn-outline-primary">View all reservations</a></div>
    @else
        <div class="table-responsive" tabindex="0" role="region" aria-label="Reservation requests table">
            <table class="table dashboard-table rv-worklist-table align-middle mb-0">
                <caption class="visually-hidden">Resident requests, services, historical appointments, current status and review actions.</caption>
                <thead><tr><th scope="col">Resident / request</th><th scope="col">Service</th><th scope="col">Appointment</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead>
                <tbody>@foreach($reservations as $reservation)
                    <tr>
                        <th scope="row"><div class="rv-person"><x-resident-avatar :user="$reservation->user" :initials="true"/><div><span class="rv-person-name">{{ $reservation->user->name }}</span><span class="reservation-reference">Request #{{ $reservation->id }} · Submitted <time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y') }}</time></span></div></div></th>
                        <td class="rv-cell-service"><span class="reservation-service">{{ $reservation->service->name }}</span></td>
                        <td><time class="rv-time" datetime="{{ $reservation->appointment->date->toDateString() }}">{{ $reservation->appointment->date->format('M j, Y') }}</time><span class="reservation-reference">{{ \Illuminate\Support\Carbon::parse($reservation->appointment->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->appointment->end_time)->format('g:i A') }}</span></td>
                        <td><x-reservation-status :status="$reservation->status"/></td>
                        <td class="rv-row-action">
                            @if(array_key_exists($reservation->id, $markMissed))
                                @if($markMissed[$reservation->id] === null)
                                    <div class="mb-2">@include('admin.reservations.mark-missed', ['compact' => true])</div>
                                @else
                                    <p class="app-note text-start mb-2">Mark as missed: {{ $markMissed[$reservation->id] }}</p>
                                @endif
                            @endif
                            <a href="{{ route('admin.reservations.show', $reservation) }}" class="recent-open" aria-label="View request #{{ $reservation->id }}"><span class="rv-row-label">View request</span><x-app-icon name="arrow"/></a>
                        </td>
                    </tr>
                @endforeach</tbody>
            </table>
        </div>
        <div class="rv-pagination"><span class="app-note">Showing {{ $reservations->firstItem() }}–{{ $reservations->lastItem() }} of {{ $reservations->total() }} reservations</span>{{ $reservations->links('pagination.civic') }}</div>
    @endif
</section>
@endsection
