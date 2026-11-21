@extends('layouts.authenticated')
@section('dashboard')
<x-page-intro subtitle="Tickets are issued after approval. Keep them private.">
    <x-slot:actions><a href="{{ route('resident.reservations.index') }}" class="btn btn-outline-primary">My Reservations</a></x-slot:actions>
</x-page-intro>
<section class="dashboard-panel resident-reservation-list" aria-labelledby="ticket-list-heading">
    <div class="dashboard-panel-heading">
        <h2 id="ticket-list-heading"><span class="panel-icon is-gold" aria-hidden="true"><x-app-icon name="qr"/></span>Your tickets</h2>
        @if($tickets->total() > 0)<span class="panel-count">{{ number_format($tickets->total()) }} {{ \Illuminate\Support\Str::plural('ticket', $tickets->total()) }}</span>@endif
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
        <div class="rv-list is-grid">
            @foreach($tickets as $ticket)
                @php($ticketStatus = $ticket->reservation->status)
                <article @class(['rv-card', 'rv-ticket-card', 'tone-'.$ticketStatus->value, 'is-historical' => $ticketStatus === \App\Enums\ReservationStatus::Completed]) aria-labelledby="ticket-{{ $ticket->id }}">
                    <div class="rv-ticket-row">
                        <span class="rv-ticket-mini" aria-hidden="true"><x-app-icon name="qr"/></span>
                        <div>
                            <span class="rv-card-ref">Request #{{ $ticket->reservation->id }}</span>
                            <h3 id="ticket-{{ $ticket->id }}">{{ $ticket->reservation->service->name }}</h3>
                        </div>
                    </div>
                    <div class="rv-card-top"><x-reservation-status :status="$ticketStatus"/>@if($ticketStatus === \App\Enums\ReservationStatus::Completed)<span class="rv-badge is-muted">Historical ticket · Request completed</span>@endif</div>
                    <dl class="rv-meta">
                        <div><dt>Schedule date</dt><dd>{{ $ticket->reservation->appointment->date->format('M j, Y') }}</dd></div>
                        <div><dt>Time</dt><dd>{{ \Illuminate\Support\Carbon::parse($ticket->reservation->appointment->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($ticket->reservation->appointment->end_time)->format('g:i A') }}</dd></div>
                        <div><dt>Issued</dt><dd>{{ $ticket->generated_at->timezone('Asia/Manila')->format('M j, Y') }}</dd></div>
                    </dl>
                    <div class="rv-card-foot"><span></span><a href="{{ route('resident.qr-tickets.show', $ticket) }}" class="panel-link" aria-label="View QR Ticket for request #{{ $ticket->reservation->id }}">View QR Ticket <x-app-icon name="arrow"/></a></div>
                </article>
            @endforeach
        </div>
        <div class="rv-pagination"><span class="app-note">Showing {{ $tickets->firstItem() }}–{{ $tickets->lastItem() }} of {{ $tickets->total() }} tickets</span>{{ $tickets->links('pagination.civic') }}</div>
    @endif
</section>
@endsection
