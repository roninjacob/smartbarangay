@extends('layouts.authenticated')
@section('dashboard')
<div class="reservation-wizard">
    <a href="{{ route('resident.qr-tickets.index') }}" class="service-back-link reservation-details-back"><x-app-icon name="arrow"/>Back to My QR Tickets</a>
    <section class="card dashboard-panel reservation-panel reservation-detail" aria-labelledby="qr-ticket-heading">
        <div class="reservation-detail-header"><div><span class="reservation-step-label">REQUEST #{{ $reservation->id }}</span><h2 id="qr-ticket-heading">{{ $reservation->service->name }}</h2></div><x-reservation-status :status="$reservation->status"/></div>
        <div class="reservation-notice">
            @if($reservation->status === \App\Enums\ReservationStatus::Completed)
                <strong>Historical ticket — request completed</strong><p class="mb-0">This ticket is retained for your records. It is not an active pickup ticket.</p>
            @elseif($reservation->status->canReceiveQrTicket())
                <strong>{{ $reservation->status === \App\Enums\ReservationStatus::ReadyForPickup ? 'Your document is ready for pickup' : 'Your QR ticket is available' }}</strong>
                <p class="mb-0">{{ $reservation->status->residentMessage() }}</p>
            @else
                <strong>QR ticket unavailable</strong><p class="mb-0">{{ $reservation->status->residentMessage() }} This ticket cannot be presented for pickup.</p>
            @endif
        </div>
        <div class="qr-ticket-content">
            @if($reservation->status->canDisplayQrTicket())
                <figure class="qr-ticket-figure">
                    <div class="qr-ticket-image"><img src="{{ route('resident.qr-tickets.image', $ticket) }}" width="320" height="320" alt="{{ $reservation->status === \App\Enums\ReservationStatus::Completed ? 'Historical QR ticket' : 'QR ticket' }} for request #{{ $reservation->id }}"></div>
                    <figcaption><strong>SmartBarangay</strong><span>Barangay Calayo · Nasugbu, Batangas</span>@if($reservation->status === \App\Enums\ReservationStatus::Completed)<span>Historical record</span>@else<span>Present this ticket at the barangay office when your document is ready for pickup.</span>@endif</figcaption>
                </figure>
            @endif
            <div class="qr-ticket-information">
                <h3 class="h6">Ticket information</h3>
                <dl class="reservation-summary">
                    <div><dt>Request</dt><dd>#{{ $reservation->id }}</dd></div>
                    <div><dt>Schedule</dt><dd>{{ $reservation->schedule->date->format('l, F j, Y') }}<br>{{ \Illuminate\Support\Carbon::parse($reservation->schedule->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->schedule->end_time)->format('g:i A') }}</dd></div>
                    <div><dt>Issued</dt><dd>{{ $ticket->generated_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</dd></div>
                </dl>
                <p class="app-note">Keep this QR ticket private. Pickup eligibility is determined by the current reservation status.</p>
                <a href="{{ route('resident.reservations.show', $reservation) }}" class="btn btn-outline-primary reservation-details-link">View Reservation</a>
            </div>
        </div>
    </section>
</div>
@endsection
