@extends('layouts.authenticated')
@section('dashboard')
<section class="dashboard-welcome admin-welcome" aria-labelledby="welcome-heading">
    <div class="welcome-copy"><span class="welcome-eyebrow">BARANGAY OPERATIONS</span><h2 id="welcome-heading">Welcome, {{ auth()->user()->name }}</h2><p>A clear view of your community's requests.<br>Keep Barangay Calayo services moving.</p></div>
    <div class="welcome-today"><span class="welcome-today-icon"><x-app-icon name="calendar"/></span><div><strong>{{ number_format($todayReservations) }}</strong><span>scheduled today</span><small>All appointment statuses · Philippine time</small></div></div>
</section>

<section class="row g-3 dashboard-stats" aria-label="Barangay operations summary">
    <div class="col-6 col-xl-3"><x-dashboard-stat label="Needs attention" :value="$needsAttention" icon="status" note="Pending and under review" tone="gold"/></div>
    <div class="col-6 col-xl-3"><x-dashboard-stat label="Ready for pickup" :value="$counts[\App\Enums\ReservationStatus::ReadyForPickup->value]" icon="services" note="Documents awaiting collection" tone="green"/></div>
    <div class="col-6 col-xl-3"><x-dashboard-stat label="Total reservations" :value="$totalReservations" icon="document" note="All requests, across all statuses"/></div>
    <div class="col-6 col-xl-3"><x-dashboard-stat label="Registered residents" :value="$totalResidents" icon="users" note="All Resident accounts"/></div>
</section>

<div class="row g-4 dashboard-content-grid">
    <div class="col-xl-8"><x-recent-reservations :reservations="$recentReservations" :admin="true"/>
        <section class="dashboard-guidance" aria-labelledby="operations-heading"><span class="guidance-icon"><x-app-icon name="services"/></span><div><h2 id="operations-heading">Your operations workspace</h2><p>Open Reservation Management to review requests and record their next status. Service and schedule configuration are available in the sidebar.</p></div></section>
    </div>
    <div class="col-xl-4 dashboard-aside"><x-dashboard-statuses :statuses="$statuses" :counts="$counts" :total="$totalReservations"/>
        <section class="dashboard-quick-actions" aria-labelledby="quick-actions-heading"><h2 id="quick-actions-heading">Quick access</h2>
            <a class="account-quick-link" href="{{ route('admin.reservations.index') }}"><x-app-icon name="document"/><span>Reservation Management</span><x-app-icon name="arrow"/></a>
            <button type="button" class="account-quick-link" data-bs-toggle="modal" data-bs-target="#account-summary"><x-app-icon name="account"/><span>View my profile</span><x-app-icon name="arrow"/></button>
            <p class="app-note mb-0">Items marked “Soon” are not yet available. Your dashboard reflects current database records.</p>
        </section>
    </div>
</div>
@endsection
