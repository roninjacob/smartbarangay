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
            <div><dt>Service Fee</dt><dd>{{ \App\Support\ServiceFee::format($reservation->fee_snapshot) }}</dd></div>
            <div><dt>Schedule date</dt><dd><time datetime="{{ $reservation->appointment->date->toDateString() }}">{{ $reservation->appointment->date->format('l, F j, Y') }}</time></dd></div>
            <div><dt>Time</dt><dd>{{ \Illuminate\Support\Carbon::parse($reservation->appointment->start_time)->format('g:i A') }} – {{ \Illuminate\Support\Carbon::parse($reservation->appointment->end_time)->format('g:i A') }}</dd></div>
            <div><dt>Submitted</dt><dd><time datetime="{{ $reservation->created_at->toIso8601String() }}">{{ $reservation->created_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</time></dd></div>
            <div><dt>Last updated</dt><dd><time datetime="{{ $reservation->updated_at->toIso8601String() }}">{{ $reservation->updated_at->timezone('Asia/Manila')->format('F j, Y, g:i A') }}</time></dd></div>
        </dl>
        @include('reservations.payment-status', ['admin' => false])
        @include('reservations.appointment-note')
        <p class="app-note">Submission and update times are shown in Philippine time.</p>
        <a href="{{ route('resident.request-status.show', $reservation) }}" class="btn btn-outline-primary reservation-details-link">Track request status</a>
        @include('resident.reservations.historical-requirements')
        @include('resident.reservations.document-information')
        @include('reservations.generated-actions', ['documentRole' => 'resident'])
        @if($reservation->document_template_snapshot)<a class="btn btn-outline-primary mt-3" href="{{ route('resident.reservations.document-preview.show', $reservation) }}">Preview Document</a>@endif
        @include('resident.reservations.attachments', ['attachmentRoute' => 'resident.reservations.attachments.download'])
    </section>
    @include('resident.reservations.cancellation-form')
    <p class="reservation-help">For assistance with your request, contact the Barangay Calayo office in Nasugbu, Batangas.</p>
</div>
@endsection
