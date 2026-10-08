@extends('layouts.authenticated')
@section('dashboard')
<div class="reservation-wizard">
    <a href="{{ route('resident.reservations.index') }}" class="service-back-link reservation-details-back"><x-app-icon name="arrow"/>Back to My Reservations</a>
    <section class="card dashboard-panel reservation-panel reservation-detail" aria-labelledby="reservation-detail-heading">
        <div class="reservation-detail-header">
            <div><span class="reservation-step-label">REQUEST #{{ $reservation->id }}</span><h2 id="reservation-detail-heading">{{ $reservation->service->name }}</h2></div>
            <x-reservation-status :status="$reservation->status"/>
        </div>
        @if($reservation->service->description)<p class="reservation-service-description">{{ $reservation->service->description }}</p>@endif
        <h3 class="h6 mt-4">Reservation information</h3>
        <dl class="reservation-summary">
            <div><dt>Schedule date</dt><dd><time datetime="{{ $reservation->schedule->date->toDateString() }}">{{ $reservation->schedule->date->format('l, F j, Y') }}</time></dd></div>
            <div><dt>Time</dt><dd>{{ \Illuminate\Support\Carbon::parse($reservation->schedule->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->schedule->end_time)->format('g:i A') }}</dd></div>
            <div><dt>Submitted</dt><dd><time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</time></dd></div>
            <div><dt>Last updated</dt><dd><time datetime="{{ $reservation->updated_at->toIso8601String() }}">{{ $reservation->updated_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</time></dd></div>
        </dl>
        <p class="app-note">Submission and update times are shown in Philippine time.</p>
        <a href="{{ route('resident.request-status.show', $reservation) }}" class="btn btn-outline-primary reservation-details-link">Track request status</a>
        <h3 class="h6 mt-4">Service requirements</h3>
        <p class="text-secondary">The barangay’s current requirements for this service. Review what to prepare.</p>
        @include('resident.reservations.requirements-list', ['service' => $reservation->service])
        @include('resident.reservations.attachments', ['attachmentRoute' => 'resident.reservations.attachments.download'])
    </section>
    @include('resident.reservations.cancellation-form')
    <p class="reservation-help">For assistance with your request, contact the Barangay Calayo office in Nasugbu, Batangas.</p>
</div>
@endsection
