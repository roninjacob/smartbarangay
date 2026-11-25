@extends('layouts.authenticated')
@section('breadcrumbs')<li><a href="{{ route('resident.reservations.index') }}">My Reservations</a></li>@endsection
@section('dashboard')
@php
    $status = $reservation->status;
    $date = $reservation->appointment->date;
    $timeRange = \Illuminate\Support\Carbon::parse($reservation->appointment->start_time)->format('g:i A').' – '.\Illuminate\Support\Carbon::parse($reservation->appointment->end_time)->format('g:i A');
@endphp
<a href="{{ route('resident.reservations.index') }}" class="rv-back reservation-details-back"><x-app-icon name="arrow"/>Back to My Reservations</a>
<div class="rv-detail-grid">
    <section class="rv-summary tone-{{ $status->value }}" aria-labelledby="reservation-detail-heading">
        <div class="rv-summary-top">
            <div>
                <span class="rv-summary-ref">Request #{{ $reservation->id }}</span>
                <h2 id="reservation-detail-heading">{{ $reservation->service->name }}</h2>
                @if($reservation->service->description)<p class="rv-summary-desc">{{ $reservation->service->description }}</p>@endif
            </div>
            <x-reservation-status :status="$status"/>
        </div>
        <div class="rv-summary-row">
            <div class="rv-date">
                <span class="rv-date-block" aria-hidden="true"><span class="rv-date-month">{{ $date->format('M') }}</span><span class="rv-date-day">{{ $date->format('j') }}</span></span>
                <span class="rv-date-text"><strong><time datetime="{{ $date->toDateString() }}">{{ $date->format('l, F j, Y') }}</time></strong><span>{{ $timeRange }}</span></span>
            </div>
        </div>
        <x-status-stepper :status="$status"/>
    </section>

    <aside class="rv-detail-aside" aria-label="Request status and actions">
        <section class="card dashboard-panel rv-section" aria-labelledby="next-step-heading">
            <div class="rv-side-block">
                <div class="rv-callout tone-{{ $status->value }}">
                    <span class="rv-callout-icon" aria-hidden="true"><x-app-icon name="bell"/></span>
                    <div>
                        <strong id="next-step-heading">Your next step</strong>
                        <p>{{ $status->residentMessage() }}</p>
                        @if($residentMessage)<p><strong>Message from the office:</strong> {{ $residentMessage }}</p>@endif
                        @if($reservation->qrTicket && $status->canDisplayQrTicket())
                            <a class="btn btn-primary btn-sm" href="{{ route('resident.qr-tickets.show', $reservation->qrTicket) }}"><x-app-icon name="qr"/> {{ $status === \App\Enums\ReservationStatus::Completed ? 'View historical QR ticket' : 'Show QR Ticket' }}</a>
                        @endif
                        @if($status === \App\Enums\ReservationStatus::ReadyForPickup)
                            <p class="mt-2">Review the payment details below before visiting. Follow the office collection instructions.</p>
                        @endif
                    </div>
                </div>
            </div>
            <div class="rv-side-block">@include('reservations.payment-status', ['admin' => false])</div>
            <div class="rv-side-block"><a href="{{ route('resident.request-status.show', $reservation) }}" class="btn btn-outline-primary w-100">Track request status</a></div>
        </section>
        @include('resident.reservations.cancellation-form')
        @include('reservations.followups')
    </aside>

    <div class="rv-detail-main">
        <section class="card dashboard-panel rv-section" aria-labelledby="reservation-info-heading">
            <div class="dashboard-panel-heading"><h2 id="reservation-info-heading"><span class="panel-icon is-green" aria-hidden="true"><x-app-icon name="calendar"/></span>Reservation information</h2></div>
            <div class="rv-section-body">
                <dl class="rv-facts">
                    <div><dt>Service Fee</dt><dd>{{ \App\Support\ServiceFee::format($reservation->fee_snapshot) }}</dd></div>
                    <div><dt>Schedule date</dt><dd><time datetime="{{ $date->toDateString() }}">{{ $date->format('M j, Y') }}</time></dd></div>
                    <div><dt>Time</dt><dd>{{ $timeRange }}</dd></div>
                    <div><dt>Submitted</dt><dd><time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('M j, Y, g:i A') }}</time></dd></div>
                    <div><dt>Last updated</dt><dd><time datetime="{{ $reservation->updated_at->toIso8601String() }}">{{ $reservation->updated_at->timezone('Asia/Manila')->format('M j, Y, g:i A') }}</time></dd></div>
                </dl>
                @include('reservations.appointment-note')
                <p class="app-note mt-2 mb-0">Times are shown in Philippine time.</p>
                @include('resident.reservations.document-information')
            </div>
        </section>
        <section class="card dashboard-panel rv-section" aria-labelledby="requirements-heading">
            <div class="dashboard-panel-heading"><h2 id="requirements-heading"><span class="panel-icon is-gold" aria-hidden="true"><x-app-icon name="document"/></span>Requirements and documents</h2></div>
            <div class="rv-section-body">
                @include('resident.reservations.historical-requirements')
                @include('resident.reservations.attachments', ['attachmentRoute' => 'resident.reservations.attachments.download'])
                @if($reservation->document_template_snapshot)<a class="btn btn-outline-primary mt-3" href="{{ route('resident.reservations.document-preview.show', $reservation) }}">Preview Document</a>@endif
            </div>
        </section>
    </div>
</div>
@endsection
