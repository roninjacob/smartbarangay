@extends('layouts.authenticated')
@section('dashboard')
<x-page-intro subtitle="Where each of your document requests is right now.">
    <x-slot:actions><a href="{{ route('resident.reservations.create') }}" class="btn btn-primary"><x-app-icon name="plus"/> New Reservation</a></x-slot:actions>
</x-page-intro>
<section class="dashboard-panel resident-reservation-list" aria-labelledby="tracking-heading">
    <div class="dashboard-panel-heading">
        <h2 id="tracking-heading"><span class="panel-icon is-teal" aria-hidden="true"><x-app-icon name="status"/></span>Your requests</h2>
        @if($reservations->total() > 0)<span class="panel-count">{{ number_format($reservations->total()) }} {{ \Illuminate\Support\Str::plural('request', $reservations->total()) }}</span>@endif
    </div>
    @if($reservations->isEmpty())
        <div class="service-empty"><span class="service-empty-icon"><x-app-icon name="status"/></span><h3>{{ $reservations->total() === 0 ? 'No requests to track yet' : 'No requests on this page' }}</h3><p>{{ $reservations->total() === 0 ? 'Submit a reservation to start tracking your request.' : 'Return to the first page to see your requests.' }}</p><a href="{{ $reservations->total() === 0 ? route('resident.reservations.create') : route('resident.request-status.index') }}" class="btn btn-outline-primary">{{ $reservations->total() === 0 ? 'New Reservation' : 'Back to first page' }}</a></div>
    @else
        <div class="rv-list is-grid">
            @foreach($reservations as $reservation)
                <article class="rv-card tone-{{ $reservation->status->value }}" aria-labelledby="track-{{ $reservation->id }}">
                    <div class="rv-card-top"><span class="rv-card-ref">Request #{{ $reservation->id }}</span><x-reservation-status :status="$reservation->status"/></div>
                    <h3 id="track-{{ $reservation->id }}">{{ $reservation->service->name }}</h3>
                    <x-status-stepper :status="$reservation->status" :compact="true"/>
                    <p class="rv-card-message">{{ $reservation->status->residentMessage() }}</p>
                    <dl class="rv-meta">
                        <div><dt>Schedule</dt><dd><time datetime="{{ $reservation->appointment->date->toDateString() }}">{{ $reservation->appointment->date->format('M j, Y') }}</time> · {{ \Illuminate\Support\Carbon::parse($reservation->appointment->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->appointment->end_time)->format('g:i A') }}</dd></div>
                        <div><dt>Submitted</dt><dd><time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y') }}</time></dd></div>
                        <div><dt>Latest update</dt><dd><time datetime="{{ $reservation->updated_at->toIso8601String() }}">{{ $reservation->updated_at->timezone('Asia/Manila')->format('M j, Y, g:i A') }}</time></dd></div>
                    </dl>
                    <div class="rv-card-foot"><span></span><a class="panel-link" href="{{ route('resident.request-status.show', $reservation) }}" aria-label="Track request #{{ $reservation->id }}">View progress <x-app-icon name="arrow"/></a></div>
                </article>
            @endforeach
        </div>
        <div class="rv-pagination"><span class="app-note">Showing {{ $reservations->firstItem() }}–{{ $reservations->lastItem() }} of {{ $reservations->total() }} requests</span>{{ $reservations->links('pagination.civic') }}</div>
    @endif
</section>
@endsection
