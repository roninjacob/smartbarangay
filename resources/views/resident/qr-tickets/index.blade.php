@extends('layouts.authenticated')
@section('dashboard')
<section class="dashboard-panel resident-reservation-list" aria-labelledby="ticket-list-heading">
    <div class="dashboard-panel-heading reservation-list-heading">
        <div><h2 id="ticket-list-heading">Your reservation tickets</h2><p class="panel-description">QR tickets are issued after approval. Keep your ticket private.</p></div>
        <a href="{{ route('resident.reservations.index') }}" class="btn btn-outline-primary">My Reservations</a>
    </div>
    @if($tickets->total() === 0)
        <div class="service-empty">
            <span class="service-empty-icon"><x-app-icon name="qr"/></span>
            <h3>No QR tickets yet</h3><p>QR tickets are issued after an eligible reservation is approved.</p>
            <a href="{{ route('resident.reservations.index') }}" class="btn btn-outline-primary">View your reservations</a>
        </div>
    @elseif($tickets->isEmpty())
        <div class="service-empty"><h3>No tickets on this page</h3><p>Return to the first page to see your tickets.</p><a href="{{ route('resident.qr-tickets.index') }}" class="btn btn-outline-primary">Back to first page</a></div>
    @else
        @foreach($tickets as $ticket)
            <article class="resident-reservation-row" aria-labelledby="ticket-{{ $ticket->id }}">
                <div class="reservation-row-service">
                    <span class="reservation-reference">Request #{{ $ticket->reservation->id }}</span>
                    <h3 id="ticket-{{ $ticket->id }}">{{ $ticket->reservation->service->name }}</h3>
                    <x-reservation-status :status="$ticket->reservation->status"/>
                    @if($ticket->reservation->status === \App\Enums\ReservationStatus::Completed)<p class="app-note mt-2 mb-0">Historical ticket · Request completed</p>@endif
                </div>
                <dl class="reservation-row-facts">
                    <div><dt>Schedule date</dt><dd>{{ $ticket->reservation->schedule->date->format('M j, Y') }}</dd></div>
                    <div><dt>Time</dt><dd>{{ \Illuminate\Support\Carbon::parse($ticket->reservation->schedule->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($ticket->reservation->schedule->end_time)->format('g:i A') }}</dd></div>
                    <div><dt>Issued</dt><dd>{{ $ticket->generated_at->timezone('Asia/Manila')->format('M j, Y') }}</dd></div>
                </dl>
                <a href="{{ route('resident.qr-tickets.show', $ticket) }}" class="btn btn-outline-primary reservation-details-link" aria-label="View QR Ticket for request #{{ $ticket->reservation->id }}">View QR Ticket</a>
            </article>
        @endforeach
        <div class="service-pagination"><span class="app-note">Showing {{ $tickets->firstItem() }}–{{ $tickets->lastItem() }} of {{ $tickets->total() }} tickets</span>{{ $tickets->links('pagination::bootstrap-5') }}</div>
    @endif
</section>
@endsection
