@extends('layouts.authenticated')
@section('dashboard')
<section class="dashboard-welcome resident-welcome" aria-labelledby="welcome-heading">
    <div class="welcome-copy"><span class="welcome-eyebrow">YOUR COMMUNITY, CONNECTED</span><h2 id="welcome-heading">Welcome, {{ auth()->user()->name }}</h2><p>Your barangay services, in one place.<br>Stay up to date with your document requests.</p><span class="welcome-location">Barangay Calayo · Nasugbu, Batangas</span></div>
    <div class="welcome-art" aria-hidden="true"><x-app-icon name="services"/><span>Reserve. Track. Verify.</span></div>
</section>

<section class="row g-3 dashboard-stats" aria-label="Your reservation summary">
    <div class="col-6 col-xl-3"><x-dashboard-stat label="Total reservations" :value="$totalReservations" icon="document" note="All your document requests"/></div>
    <div class="col-6 col-xl-3"><x-dashboard-stat label="In progress" :value="$inProgress" icon="status" note="Pending, under review or approved" tone="gold"/></div>
    <div class="col-6 col-xl-3"><x-dashboard-stat label="Ready for pickup" :value="$counts[\App\Enums\ReservationStatus::ReadyForPickup->value]" icon="services" note="Documents ready to collect" tone="green"/></div>
    <div class="col-6 col-xl-3"><x-dashboard-stat label="Completed" :value="$counts[\App\Enums\ReservationStatus::Completed->value]" icon="check" note="Successfully completed requests"/></div>
</section>

<div class="row g-4 dashboard-content-grid">
    <div class="col-xl-8"><x-recent-reservations :reservations="$recentReservations"/>
        <section class="dashboard-guidance" aria-labelledby="next-step-heading">
            <span class="guidance-icon"><x-app-icon :name="$counts[\App\Enums\ReservationStatus::ReadyForPickup->value] > 0 ? 'services' : 'status'"/></span>
            <div><h2 id="next-step-heading">Your next step</h2>
                @if($counts[\App\Enums\ReservationStatus::ReadyForPickup->value] > 0)
                    <p>You have documents ready for pickup. Contact the Barangay Calayo office for collection guidance.</p>
                @elseif($inProgress > 0)
                    <p>Your requests are being processed. Check this dashboard for updates from Barangay Calayo.</p>
                @else
                    <p>Need a barangay document? Start a new reservation to choose your service and schedule.</p>
                @endif
            </div>
        </section>
    </div>
    <div class="col-xl-4 dashboard-aside"><x-dashboard-statuses :statuses="$statuses" :counts="$counts" :total="$totalReservations"/>
        <section class="dashboard-quick-actions" aria-labelledby="quick-actions-heading"><h2 id="quick-actions-heading">Quick access</h2>
            <a class="account-quick-link" href="{{ route('resident.reservations.create') }}"><x-app-icon name="plus"/><span>New Reservation</span><x-app-icon name="arrow"/></a>
            <a class="account-quick-link" href="{{ route('resident.reservations.index') }}"><x-app-icon name="document"/><span>My Reservations</span><x-app-icon name="arrow"/></a>
            <button type="button" class="account-quick-link" data-bs-toggle="modal" data-bs-target="#account-summary"><x-app-icon name="account"/><span>View my profile</span><x-app-icon name="arrow"/></button>
            <p class="app-note mb-0">Choose New Reservation to submit a document request. Items marked “Soon” are not yet available.</p>
        </section>
    </div>
</div>
@endsection
