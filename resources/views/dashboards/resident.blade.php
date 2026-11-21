@extends('layouts.authenticated')
@section('dashboard')
@php
    $now = now('Asia/Manila');
    $greeting = $now->hour < 12 ? 'Good morning' : ($now->hour < 18 ? 'Good afternoon' : 'Good evening');
    $viewer = auth()->user();
    $firstName = filled($viewer->first_name) ? $viewer->first_name : \Illuminate\Support\Str::before(trim($viewer->name).' ', ' ');
    $canSubmit = $viewer->canSubmitReservations();
    $latest = $recentReservations->first();
    $readyCount = $counts[\App\Enums\ReservationStatus::ReadyForPickup->value];
@endphp
@include('partials.residency-status', ['resident' => $viewer])

<section class="dash-hero" aria-labelledby="welcome-heading">
    <div class="dash-hero-bg" aria-hidden="true"></div>
    <div class="dash-hero-copy">
        <p class="dash-hero-date"><time datetime="{{ $now->toDateString() }}">{{ $now->format('l, F j, Y') }}</time></p>
        <h2 id="welcome-heading">{{ $greeting }}, {{ $firstName }}</h2>
        <p>Request barangay documents and follow each request here.</p>
        <div class="dash-hero-actions">
            <a href="{{ route($canSubmit ? 'resident.reservations.create' : 'resident.profile.edit') }}" class="btn btn-light">{{ $canSubmit ? 'Request a document' : 'Review your profile' }} <x-app-icon name="arrow"/></a>
        </div>
    </div>
    @if($latest)
        <a class="dash-hero-card dash-hero-latest" href="{{ route('resident.reservations.show', $latest) }}" aria-label="Latest request: {{ $latest->service->name }}, {{ $latest->status->label() }}">
            <small>Latest request</small>
            <strong class="dash-hero-latest-name">{{ $latest->service->name }}</strong>
            <x-reservation-status :status="$latest->status"/>
            <span class="dash-hero-latest-date"><x-app-icon name="calendar"/>{{ $latest->appointment->date->format('M j, Y') }}</span>
        </a>
    @endif
</section>

<section class="dash-stats" aria-label="Your reservation summary">
    <x-dashboard-stat label="Total reservations" :value="$totalReservations" icon="document"/>
    <x-dashboard-stat label="In progress" :value="$inProgress" icon="status" note="Pending, under review or approved" tone="gold"/>
    <x-dashboard-stat label="Ready for pickup" :value="$readyCount" icon="services" tone="green"/>
    <x-dashboard-stat label="Completed" :value="$counts[\App\Enums\ReservationStatus::Completed->value]" icon="check" tone="violet"/>
</section>

<div class="dash-grid">
    <div class="dash-main">
        <x-recent-reservations :reservations="$recentReservations"/>
    </div>
    <div class="dash-side">
        <section @class(['dash-tip', 'is-ready' => $readyCount > 0]) aria-labelledby="tip-heading">
            <span class="dash-tip-icon" aria-hidden="true"><x-app-icon :name="$readyCount > 0 ? 'qr' : 'status'"/></span>
            <div>
                @if($readyCount > 0)
                    <h2 id="tip-heading">Ready for pickup</h2>
                    <p>You have documents ready for pickup. Bring your QR ticket to the Barangay Calayo office.</p>
                    <a class="panel-link" href="{{ route('resident.qr-tickets.index') }}">Open my QR tickets <x-app-icon name="arrow"/></a>
                @elseif($inProgress > 0)
                    <h2 id="tip-heading">Requests in progress</h2>
                    <p>The barangay office is processing your requests. You get a notification when a status changes.</p>
                @elseif(! $canSubmit)
                    <h2 id="tip-heading">Residency review</h2>
                    <p>Keep your profile up to date while the barangay office reviews your residency.</p>
                @else
                    <h2 id="tip-heading">Need a document?</h2>
                    <p>Start a new reservation to choose a service and an available schedule.</p>
                @endif
            </div>
        </section>
        <x-dashboard-statuses :statuses="$statuses" :counts="$counts" :total="$totalReservations"/>
        <section class="card dashboard-panel" aria-labelledby="quick-actions-heading">
            <div class="dashboard-panel-heading"><h2 id="quick-actions-heading">Quick access</h2></div>
            <div class="quick-tiles">
                @if($canSubmit)<a class="quick-tile" href="{{ route('resident.reservations.create') }}"><span class="quick-tile-icon"><x-app-icon name="plus"/></span>New reservation</a>@endif
                <a class="quick-tile" href="{{ route('resident.reservations.index') }}"><span class="quick-tile-icon"><x-app-icon name="document"/></span>My reservations</a>
                <a class="quick-tile" href="{{ route('resident.qr-tickets.index') }}"><span class="quick-tile-icon"><x-app-icon name="qr"/></span>QR tickets</a>
                <a class="quick-tile" href="{{ route('resident.request-status.index') }}"><span class="quick-tile-icon"><x-app-icon name="status"/></span>Request status</a>
            </div>
        </section>
    </div>
</div>
@endsection
