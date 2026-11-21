@extends('layouts.authenticated')
@section('breadcrumbs')<li><a href="{{ route('resident.qr-tickets.index') }}">QR Tickets</a></li>@endsection
@section('dashboard')
@php
    $status = $reservation->status;
    $completed = $status === \App\Enums\ReservationStatus::Completed;
@endphp
<div class="rv-ticket-page">
    <a href="{{ route('resident.qr-tickets.index') }}" class="rv-back reservation-details-back"><x-app-icon name="arrow"/>Back to My QR Tickets</a>
    <div class="rv-callout tone-{{ $status->value }} mb-4">
        <span class="rv-callout-icon" aria-hidden="true"><x-app-icon name="{{ $completed ? 'status' : 'qr' }}"/></span>
        <div>
            @if($completed)
                <strong>Historical ticket — request completed</strong><p>This ticket is retained for your records. It is not an active pickup ticket.</p>
            @elseif($status->canReceiveQrTicket())
                <strong>{{ $status === \App\Enums\ReservationStatus::ReadyForPickup ? 'Your document is ready for pickup' : 'Your QR ticket is available' }}</strong>
                <p>{{ $status->residentMessage() }}</p>
            @else
                <strong>QR ticket unavailable</strong><p>{{ $status->residentMessage() }} This ticket cannot be presented for pickup.</p>
            @endif
        </div>
    </div>

    <article @class(['rv-ticket', 'is-historical' => $completed]) aria-labelledby="qr-ticket-heading">
        <header class="rv-ticket-head">
            <x-brand-mark/>
            <div><strong>SmartBarangay</strong><small>Barangay Calayo · Nasugbu, Batangas</small></div>
        </header>
        @if($status->canDisplayQrTicket())
            <figure class="rv-ticket-qr m-0">
                <div class="rv-ticket-qr-frame"><img src="{{ route('resident.qr-tickets.image', $ticket) }}" width="320" height="320" alt="{{ $completed ? 'Historical QR ticket' : 'QR ticket' }} for request #{{ $reservation->id }}"></div>
                <figcaption><p>{{ $completed ? 'Historical record' : 'Present this ticket at the barangay office when your document is ready for pickup.' }}</p></figcaption>
            </figure>
        @endif
        <div class="rv-ticket-cut" aria-hidden="true"></div>
        <div class="rv-ticket-body">
            <div class="rv-card-top"><span class="rv-card-ref">Request #{{ $reservation->id }}</span><x-reservation-status :status="$status"/></div>
            <h2 id="qr-ticket-heading">{{ $reservation->service->name }}</h2>
            <dl class="rv-ticket-grid">
                <div><dt>Date</dt><dd>{{ $reservation->appointment->date->format('l, F j, Y') }}</dd></div>
                <div><dt>Time</dt><dd>{{ \Illuminate\Support\Carbon::parse($reservation->appointment->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->appointment->end_time)->format('g:i A') }}</dd></div>
                <div><dt>Request</dt><dd>#{{ $reservation->id }}</dd></div>
                <div><dt>Issued</dt><dd>{{ $ticket->generated_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</dd></div>
            </dl>
            <p class="app-note mb-0">Keep this QR ticket private. Pickup eligibility is determined by the current reservation status.</p>
        </div>
    </article>
    <div class="rv-ticket-actions">
        <a href="{{ route('resident.reservations.show', $reservation) }}" class="btn btn-outline-primary reservation-details-link">View Reservation</a>
    </div>
</div>
@endsection
