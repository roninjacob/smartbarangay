@extends('layouts.authenticated')
@section('dashboard')
@php
    $now = now('Asia/Manila');
    $greeting = $now->hour < 12 ? 'Good morning' : ($now->hour < 18 ? 'Good afternoon' : 'Good evening');
    $viewer = auth()->user();
    $firstName = filled($viewer->first_name) ? $viewer->first_name : \Illuminate\Support\Str::before(trim($viewer->name).' ', ' ');
@endphp
<section class="dash-hero" aria-labelledby="welcome-heading">
    <div class="dash-hero-bg" aria-hidden="true"></div>
    <div class="dash-hero-copy">
        <p class="dash-hero-date"><time datetime="{{ $now->toDateString() }}">{{ $now->format('l, F j, Y') }}</time></p>
        <h2 id="welcome-heading">{{ $greeting }}, {{ $firstName }}</h2>
        <p>Here is today at Barangay Calayo.</p>
        <div class="dash-hero-actions">
            <a class="btn btn-light" href="{{ route('admin.reservations.index') }}"><x-app-icon name="document"/> Reservations</a>
            <a class="btn btn-outline-light" href="{{ route('admin.qr-verification.index') }}"><x-app-icon name="qr"/> QR Verification</a>
        </div>
    </div>
    <div class="dash-hero-card">
        <span class="dash-hero-card-icon" aria-hidden="true"><x-app-icon name="calendar"/></span>
        <strong data-count-to="{{ (int) $todayReservations }}">{{ number_format($todayReservations) }}</strong>
        <span>scheduled today</span>
        <small>All appointment statuses · Philippine time</small>
    </div>
</section>

<section class="dash-stats" aria-label="Barangay operations summary">
    <x-dashboard-stat label="Needs attention" :value="$needsAttention" icon="status" note="Pending and under review" tone="gold"/>
    <x-dashboard-stat label="Ready for pickup" :value="$counts[\App\Enums\ReservationStatus::ReadyForPickup->value]" icon="services" tone="teal"/>
    <x-dashboard-stat label="Total reservations" :value="$totalReservations" icon="document"/>
    <x-dashboard-stat label="Registered residents" :value="$totalResidents" icon="users" tone="indigo"/>
</section>

<section class="dash-group" aria-labelledby="collections-heading">
    <h2 id="collections-heading" class="dash-group-title">Collections</h2>
    <div class="dash-stats">
        <x-dashboard-stat label="Collections Today" :value="$collections['today']" :formatted="\App\Support\ServiceFee::format($collections['today'])" icon="cash" tone="green"/>
        <x-dashboard-stat label="Collections This Month" :value="$collections['month']" :formatted="\App\Support\ServiceFee::format($collections['month'])" icon="cash" tone="green"/>
        <x-dashboard-stat label="Paid Transactions Today" :value="$collections['paid_today']" icon="check"/>
        <x-dashboard-stat label="Ready for Pickup — Unpaid" :value="$collections['ready_unpaid']" icon="services" note="Known positive fees without a payment" tone="gold"/>
    </div>
    <p class="dash-group-note">Collections use payment time in Philippine time.</p>
</section>
@if($collections['integrity_issues'] > 0)<p class="alert alert-danger" role="alert">Payment integrity issues detected ({{ $collections['integrity_issues'] }}). Collections exclude inconsistent payment records. Review Reservation Details.</p>@endif

<div class="dash-grid">
    <div class="dash-main"><x-recent-reservations :reservations="$recentReservations" :admin="true"/></div>
    <div class="dash-side">
        <x-dashboard-statuses :statuses="$statuses" :counts="$counts" :total="$totalReservations"/>
        <section class="card dashboard-panel" aria-labelledby="quick-actions-heading">
            <div class="dashboard-panel-heading"><h2 id="quick-actions-heading"><span class="panel-icon is-gold" aria-hidden="true"><x-app-icon name="dashboard"/></span>Quick access</h2></div>
            <div class="quick-tiles">
                <a class="quick-tile" href="{{ route('admin.reservations.index') }}"><span class="quick-tile-icon"><x-app-icon name="document"/></span>Reservations</a>
                <a class="quick-tile" href="{{ route('admin.schedules.index') }}"><span class="quick-tile-icon"><x-app-icon name="calendar"/></span>Schedules</a>
                <a class="quick-tile" href="{{ route('admin.users.index') }}"><span class="quick-tile-icon"><x-app-icon name="users"/></span>Residents</a>
                <a class="quick-tile" href="{{ route('admin.reports.reservations') }}"><span class="quick-tile-icon"><x-app-icon name="chart"/></span>Reports</a>
            </div>
        </section>
    </div>
</div>
@endsection
