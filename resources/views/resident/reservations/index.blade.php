@extends('layouts.authenticated')
@section('dashboard')
<section class="dashboard-panel resident-reservation-list" aria-labelledby="reservation-list-heading">
    <div class="dashboard-panel-heading reservation-list-heading">
        <div><h2 id="reservation-list-heading">Your submitted requests</h2><p class="panel-description">Review your service requests and their current status.</p></div>
        <a href="{{ route('resident.reservations.create') }}" class="btn btn-primary">New Reservation</a>
    </div>
    @if($reservations->total() === 0)
        <div class="service-empty">
            <span class="service-empty-icon"><x-app-icon name="document"/></span>
            <h3>No reservations yet</h3><p>Start a new reservation to request a document from Barangay Calayo.</p>
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
                    <div><dt>Schedule date</dt><dd><time datetime="{{ $reservation->schedule->date->toDateString() }}">{{ $reservation->schedule->date->format('M j, Y') }}</time></dd></div>
                    <div><dt>Time</dt><dd>{{ \Illuminate\Support\Carbon::parse($reservation->schedule->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->schedule->end_time)->format('g:i A') }}</dd></div>
                    <div><dt>Submitted</dt><dd><time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y') }}</time></dd></div>
                </dl>
                <a href="{{ route('resident.reservations.show', $reservation) }}" class="btn btn-outline-primary reservation-details-link" aria-label="View Details for request #{{ $reservation->id }}">View Details</a>
            </article>
        @endforeach
        <div class="service-pagination"><span class="app-note">Showing {{ $reservations->firstItem() }}–{{ $reservations->lastItem() }} of {{ $reservations->total() }} reservations</span>{{ $reservations->links('pagination::bootstrap-5') }}</div>
    @endif
</section>
@endsection
