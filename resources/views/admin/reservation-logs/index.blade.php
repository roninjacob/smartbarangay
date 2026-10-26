@extends('layouts.authenticated')
@section('dashboard')
<section class="dashboard-panel reservation-log-panel" aria-labelledby="logs-heading">
    <div class="dashboard-panel-heading"><div><h2 id="logs-heading">Reservation activity across Barangay Calayo</h2><p class="panel-description">Read-only history from reservation records. Newest first · Philippine time.</p></div></div>
    @if($errors->any())<div class="alert alert-danger mx-3" role="alert">{{ $errors->first() }}</div>@endif
    <form method="GET" action="{{ route('admin.reservation-logs.index') }}" class="reservation-filters row g-3">
        <div class="col-md-5"><label class="form-label" for="log-search">Search requests, residents or services</label><input class="form-control" id="log-search" name="search" type="search" maxlength="255" value="{{ $filters['search'] ?? '' }}" placeholder="Request #, Resident or Service"></div>
        <div class="col-md-3"><label class="form-label" for="log-type">Activity type</label><select class="form-select" id="log-type" name="type"><option value="">All activities</option>@foreach($types as $type => $label)<option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-4 reservation-filter-actions"><button type="submit" class="btn btn-primary">Apply filters</button><a href="{{ route('admin.reservation-logs.index') }}" class="btn btn-outline-secondary">Clear</a></div>
    </form>
    @if($activities->isEmpty())<div class="service-empty"><h3>No matching activities</h3><p>Try another search or activity type. Older requests may have only partial history.</p><a href="{{ route('admin.reservation-logs.index') }}" class="btn btn-outline-primary">View all activities</a></div>@else
        <ol class="reservation-log-feed" aria-label="Recent reservation activities">
            @foreach($activities as $activity)
                <li><span class="activity-feed-marker" aria-hidden="true"><x-app-icon name="history"/></span><div class="activity-context"><a href="{{ route('admin.reservations.show', $activity['reservation_id']) }}">Request #{{ $activity['reservation_id'] }}</a><span>{{ $activity['service'] }}</span><span class="text-secondary">{{ $activity['resident'] }}</span></div>
                    <div class="activity-content">@include('admin.reservations.activity-item')</div></li>
            @endforeach
        </ol>
        <div class="service-pagination"><span class="app-note">Showing {{ $activities->firstItem() }}–{{ $activities->lastItem() }} of {{ $activities->total() }} activities</span>{{ $activities->links('pagination.civic') }}</div>
    @endif
</section>
@endsection
