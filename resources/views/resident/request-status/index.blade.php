@extends('layouts.authenticated')
@section('dashboard')
<section class="dashboard-panel resident-reservation-list" aria-labelledby="tracking-heading">
    <div class="dashboard-panel-heading reservation-list-heading"><div><h2 id="tracking-heading">Follow your requests</h2><p class="panel-description">The latest progress of your Barangay Calayo document requests.</p></div></div>
    @if($reservations->isEmpty())
        <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="status"/></span><h3>{{ $reservations->total() === 0 ? 'No requests to track yet' : 'No requests on this page' }}</h3><p>{{ $reservations->total() === 0 ? 'Submit a reservation to start tracking your request.' : 'Return to the first page to see your requests.' }}</p><a href="{{ $reservations->total() === 0 ? route('resident.reservations.create') : route('resident.request-status.index') }}" class="btn btn-outline-primary">{{ $reservations->total() === 0 ? 'New Reservation' : 'Back to first page' }}</a></div>
    @else
        @foreach($reservations as $reservation)
            <article class="request-tracking-row" aria-labelledby="track-{{ $reservation->id }}">
                <div class="reservation-row-service"><span class="reservation-reference">Request #{{ $reservation->id }}</span><h3 id="track-{{ $reservation->id }}">{{ $reservation->service->name }}</h3><x-reservation-status :status="$reservation->status"/><p class="request-status-message">{{ $reservation->status->residentMessage() }}</p></div>
                <dl class="request-tracking-facts">
                    <div><dt>Schedule</dt><dd>{{ $reservation->schedule->date->format('M j, Y') }}<span class="reservation-reference">{{ \Illuminate\Support\Carbon::parse($reservation->schedule->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->schedule->end_time)->format('g:i A') }}</span></dd></div>
                    <div><dt>Submitted</dt><dd><time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y, g:i A') }}</time></dd></div>
                    <div><dt>Latest update</dt><dd><time datetime="{{ $reservation->updated_at->toIso8601String() }}">{{ $reservation->updated_at->timezone('Asia/Manila')->format('M j, Y, g:i A') }}</time></dd></div>
                </dl>
                <a class="btn btn-outline-primary reservation-details-link" href="{{ route('resident.request-status.show', $reservation) }}" aria-label="Track request #{{ $reservation->id }}">View progress</a>
            </article>
        @endforeach
        <div class="service-pagination"><span class="app-note">Showing {{ $reservations->firstItem() }}–{{ $reservations->lastItem() }} of {{ $reservations->total() }} requests</span>{{ $reservations->links('pagination::bootstrap-5') }}</div>
    @endif
</section>
@endsection
